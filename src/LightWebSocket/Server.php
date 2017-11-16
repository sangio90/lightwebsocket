<?php


namespace LightWebSocket;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class Server implements MessageComponentInterface
{
    protected $clients = [];

    protected $channels = [];

    const MESSAGETYPE_SUBSCRIBE = 'subscribe';
    const MESSAGETYPE_UNSUBSCRIBE = 'unsubscribe';
    const MESSAGETYPE_CREATECHANNEL = 'createChannel';
    const MESSAGETYPE_COMMUNICATION = 'communication';

    public function onOpen(ConnectionInterface $conn)
    {
        $resourceId = $conn->resourceId;
        $this->clients[$resourceId] = $conn;
        $this->log('New Connection ' . $resourceId . PHP_EOL);
        $conn->send(json_encode(['resourceId' => $resourceId, 'body' => 'Resource id: ' . $resourceId . '!']));
    }

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

    public function log($message)
    {
        $filePath = '/var/log/' . date('Ymd') . '.log';
        $file = fopen($filePath, 'a+');
        fwrite($file, $message . PHP_EOL);
    }



}