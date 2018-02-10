<?php
/**
 * Get recent messages from Hipchat and post to Slack
 *
 * Source: Hipchat
 * Destination: Slack
 *
 * @link https://www.hipchat.com/docs/apiv2
 * @link https://www.hipchat.com/docs/apiv2/method/view_recent_room_history
 * @link https://api.slack.com/incoming-webhooks
 */

namespace App;

use SolutionDrive\HipchatAPIv2Client\Client;
use SolutionDrive\HipchatAPIv2Client\API\RoomAPI;
use SolutionDrive\HipchatAPIv2Client\Auth\OAuth2;

class Application
{
    protected $rooms;
    protected $srcConfig;
    protected $dstConfig;
    protected $lastMessageIdsFile;
    protected $lastMessageIds;

    public function __construct(array $config)
    {
        $this->rooms = $config['rooms'] ?? [];
        $this->srcConfig = $config['hipchat'] ?? [];
        $this->dstConfig = $config['slack'] ?? [];
        $this->lastMessageIdsFile = $config['last_message_ids_file'];

        if (! file_exists($this->lastMessageIdsFile)) {
            file_put_contents($this->lastMessageIdsFile, json_encode([]));
        }

        $this->lastMessageIds = json_decode(file_get_contents($this->lastMessageIdsFile), true) ?: [];
    }

    /**
     * Run
     *
     * @return void
     */
    public function run()
    {
        $messages = $this->getMessages(
            $this->srcConfig,
            $this->rooms,
            $this->lastMessageIds,
            $this->lastMessageIdsFile
        );

        $this->post($this->dstConfig, $this->rooms, $messages);
    }

    /**
     * Get recent messages from source
     *
     * @param  array $config
     * @param  array $rooms
     * @param  array $lastMessageIds
     * @param  string $lastMessageIdsFile
     * @return array Messages indexed by rooms
     */
    protected function getMessages(array $config, array $rooms, array $lastMessageIds, string $lastMessageIdsFile)
    {
        $token = $config['token'];
        $auth = new OAuth2($token);
        $client = new Client($auth);

        $roomAPI = new RoomAPI($client);
        $timezone = $config['timezone'];

        $result = [];
        foreach ($rooms as $srcRoom => $dstRoom) {
            $params = ['timezone' => $timezone];

            $lastMessageId = $lastMessageIds[$srcRoom] ?? null;
            if ($lastMessageId) {
                $params['not-before'] = $lastMessageId;
            }

            $roomName = rawurlencode($srcRoom); // convert space to %20 instead of +
            $messages = $roomAPI->getRecentHistory($roomName, $params);
            if ($messages) {
                $lastMessage = end($messages);
                $lastMessageIds[$srcRoom] = $lastMessage->getId();

                // Remove 1st message as it would be a duplicate of $lastMessageId for current call if it is used
                if ($lastMessageId) {
                    array_shift($messages);
                }
            }

            $result[$srcRoom] = $messages;
        }

        // Update last message ids
        file_put_contents($lastMessageIdsFile, json_encode($lastMessageIds));

        return $result;
    }

    /**
     * Post messages to destination
     *
     * @param  array $config
     * @param  array $rooms
     * @param  array $messages
     * @return void
     */
    protected function post(array $config, array $rooms, array $messages)
    {
        $handler = $this->getHandler($config['webhook_url']);

        $username = $config['username'];
        $iconEmoji = $config['icon_emoji'];
        foreach ($messages as $srcRoom => $roomMessages) {
            $dstRoom = $this->rooms[$srcRoom] ?? $config['channel'];

            foreach ($roomMessages as $message) {
                $from = $message->getFrom();
                if ('Link' === $from) {
                    continue;
                }

                $data = json_encode([
                    'text' => sprintf("[From Hipchat, %s, %s, %s]\n\n%s\n\n",
                        $srcRoom,
                        $from,
                        $message->getDate(),
                        $message->getMessage()
                    ),
                    'channel' => $dstRoom,
                    'username' => $username,
                    'icon_emoji' => $iconEmoji,
                ]);

                curl_setopt($handler, CURLOPT_POSTFIELDS, $data);
                curl_exec($handler);
            }
        }

        curl_close($handler);
    }

    /**
     * Get cURL handler for url
     *
     * @param  string $url
     * @return resource
     */
    protected function getHandler(string $url)
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
        ];

        $curlHandler = curl_init();
        curl_setopt_array($curlHandler, [
            CURLOPT_RETURNTRANSFER => true, // return value instead of output to browser
            CURLOPT_HEADER => false, // do not include headers in return value
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => 'POST',
        ]);

        return $curlHandler;
    }
}
