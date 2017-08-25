<?php


namespace LightWebSocket;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class Server implements MessageComponentInterface
{
    protected $clients = [];

    protected $channels = [];

    //Indirizzo del "trusted host", che può creare canali, iscrivere resources a dei canali, disisscrivere resources
    protected $allowedAddress;

    //Questo URL viene chiamato ogni volta che si riceve una connessione da qualsiasi host (tranne da un allowedAddress)
    protected $newConnectionPath;

    //TODO Fare in modo che alla ricezione di un particolare messaggio (che il client invierà dopo la login, con il prorpio PHPSESSID)
    //Venga chiamata una API fissa, di un ip configurabile, poi sarà l'applicazione remota a gestire le subscribe
    //Nei reports/sap ecc.. gestire un'API accessibile senza login che si aspetta un PHPSESSID e un resourceId
    //Quindi cerca nei propri user_role i ruoli legati a session->auth/user e invia un messaggio di tipo subscribe ai canali con
    //gli stessi nomi dei ruoli
    //TODO Unico problema: capire come leggere una sessione a partire dal PHPSESSID

    const MESSAGETYPE_SUBSCRIBE = 'subscribe';
    const MESSAGETYPE_UNSUBSCRIBE = 'unsubscribe';
    const MESSAGETYPE_CREATECHANNEL = 'createChannel';
    const MESSAGETYPE_COMMUNICATION = 'communication';

    public function __construct($allowedAddress = '')
    {
        $this->allowedAddress = $allowedAddress;
    }

    public function onOpen(ConnectionInterface $conn)
    {
        $resourceId = $conn->resourceId;
        $this->clients[$resourceId] = $conn;
        $this->log('New Connection ' . $resourceId . PHP_EOL);
        $conn->send(json_encode(['resourceId' => $resourceId, 'body' => 'Resource id: ' . $resourceId . '!']));
//        $this->handleNewConnection($conn);
    }

//    public function handleNewConnection(ConnectionInterface $conn)
//    {
//        $sessionId = array_key_exists('PHPSESSID', $_COOKIE) ? $_COOKIE['PHPSESSID'] : 'NO_SESSION';
//
//        $this->log('session : ' . print_r($_SESSION, true));
//
//        if ($conn->remoteAddress === $this->allowedAddress) {
//            $client = new Client(['base_uri' => 'http://' . $this->allowedAddress]);
//            try {
//                $response = $client->get($this->newConnectionPath, [
//                    'query' => [
//                        'resourceId' => $conn->resourceId,
//                        'PHPSESSID' => $sessionId,
//                    ],
//                ]);
//                echo $response->getBody()->getContents();
//            } catch (\Exception $e) {
//                echo $e->getMessage();
//            }
//        }
//    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $jsonMessage = json_decode($msg, true);
        $this->log('New message: ' . $msg . PHP_EOL);
        if ($this->validateIncomingJsonMessage($jsonMessage)) {
            $messageType = $jsonMessage['type'];
            $messageBody = $jsonMessage['body'];
            switch ($messageType) {
                case self::MESSAGETYPE_CREATECHANNEL:
                    $this->createChannel($messageBody);
                    break;
                case self::MESSAGETYPE_SUBSCRIBE:
                    $resourceToSubscribe = $jsonMessage['resourceId'];
                    $this->subscribeToChannel($resourceToSubscribe, $messageBody);
                    break;
                case self::MESSAGETYPE_UNSUBSCRIBE:
                    $resourceToUnsubscribe = $jsonMessage['resourceId'];
                    $this->unSubscribeFromChannel($resourceToUnsubscribe, $messageBody);
                    break;
                case self::MESSAGETYPE_COMMUNICATION:
                    $this->handleNormalCommunication($jsonMessage);
                    break;
            }
        }
    }

    public function handleNormalCommunication($jsonMessage = [])
    {
        $messageBody = $jsonMessage['body'];
        $notifyChannels = [];
        //Se il msg è indirizzato solo ad alcuni canali, preparo un array di utenti a cui inviarlo
        if (array_key_exists('notifyChannels', $jsonMessage)) {
            $notifyChannels = $jsonMessage['notifyChannels'];
        }
        foreach ($notifyChannels as $notifyChannel) {
            if (array_key_exists($notifyChannel, $this->channels)) {
                //Il canale esiste
                $connections = $this->channels[$notifyChannel];
                foreach ($connections as $connection) {
                    /** @var ConnectionInterface $connection */
                    $connection->send(json_encode(['body' =>$messageBody]));
                    $this->log('Sent message ' . $messageBody . ' to resource Id ' . $connection->resourceId . PHP_EOL);
                }
            }
        }
    }

    public function createChannel($channelName)
    {
        if (!array_key_exists($channelName, $this->channels)) {
            $this->channels[$channelName] = [];
            $this->log('Channel created: ' . $channelName);
        }
    }

    public function subscribeToChannel($resourceId, $channelName)
    {
        if (array_key_exists($channelName, $this->channels)) {
            $existing = array_filter($this->channels[$channelName], function($conn) use($resourceId) {
                return $conn->resourceId == $resourceId;
            });
            if (!$existing) {
                $this->channels[$channelName][] = $this->clients[$resourceId];
                $this->log('Connection ' . $resourceId . ' subscribed to channel ' . $channelName . PHP_EOL);
            }
        }
    }

    public function unSubscribeFromChannel($resourceId, $channelName)
    {
        if ($channelName == '*') {
            foreach ($this->channels as $key => $value) {
                $this->log($resourceId . ' unsubscribed from channel ' . $key);
                unset($this->channel[$key]);
            }
        } else {
            $resourcesForChannelName = $this->channels[$channelName];
            $resourcesForChannelName = array_map(function ($resource) use ($resourceId) {
                if ($resource->resourceId === $resourceId) {
                    return $resource;
                }
                return null;
            }, $resourcesForChannelName);
            if (array_key_exists($channelName, $this->channels)) {
                $this->channels[$channelName] = $resourcesForChannelName;
                $this->log('Connection ' . $resourceId . ' unsubscribed from channel ' . $channelName . PHP_EOL);
            }
        }
    }

    public function validateIncomingJsonMessage($jsonMessage)
    {
        if (!$jsonMessage) {
            $this->log('Message is not a valid JSON' . PHP_EOL);
            return false;
        }
        if (
            !array_key_exists('type', $jsonMessage) || (
                $jsonMessage['type'] !== self::MESSAGETYPE_CREATECHANNEL &&
                $jsonMessage['type'] !== self::MESSAGETYPE_SUBSCRIBE &&
                $jsonMessage['type'] !== self::MESSAGETYPE_UNSUBSCRIBE &&
                $jsonMessage['type'] !== self::MESSAGETYPE_COMMUNICATION
            )

        ) {
            $this->log('Message format not supported' . PHP_EOL);
            return false;
        }
        return true;
    }

    public function onClose(ConnectionInterface $conn) {
        $resourceId = $conn->resourceId;
        $this->log($resourceId . ' is disconnecting, unsubscribe from all channels');
        $this->unSubscribeFromChannel($resourceId, '*');
        unset($this->clients[$resourceId]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->send('connection closed due to an error: ' . $e->getMessage()); //TODO remove e->getMessage x sicurezza
        $conn->close();
    }

//    /**
//     * @param mixed $newConnectionPath
//     */
//    public function setNewConnectionPath($newConnectionPath)
//    {
//        $this->newConnectionPath = $newConnectionPath;
//    }

    public function log($message)
    {
        $filePath = '/var/log/' . date('Ymd') . '.log';
        $file = fopen($filePath, 'a+');
        fwrite($file, $message . PHP_EOL);
    }



}