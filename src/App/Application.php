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
 * @link https://api.slack.com/docs/message-attachments#attachment_structure
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

    public function __construct(array $config)
    {
        $this->srcConfig = $config['hipchat'] ?? [];
        $this->dstConfig = $config['slack'] ?? [];

        // Normalize rooms: [a, b => null, c => d] becomes [a => defaultChannel, b => defaultChannel, c => d]
        $this->rooms = [];
        $defaultDstChannel = $this->dstConfig['channel'] ?? null;
        foreach (($config['rooms'] ?? []) as $srcRoom => $dstRoom) {
            if (is_numeric($srcRoom)) { // [a] same as [0 => a]
                $srcRoom = $dstRoom;
                $dstRoom = $defaultDstChannel;
            }

            $this->rooms[$srcRoom] = $dstRoom ?: $defaultDstChannel;
        }

        // Create last message ids file if it does not exist
        $this->lastMessageIdsFile = $config['last_message_ids_file'];
        if (! file_exists($this->lastMessageIdsFile)) {
            file_put_contents($this->lastMessageIdsFile, json_encode([]));
        }
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
            $this->lastMessageIdsFile
        );

        $this->post($this->dstConfig, $this->rooms, $messages);
    }

    /**
     * Get recent messages from source
     *
     * @param  array $config
     * @param  array $rooms
     * @param  string $lastMessageIdsFile
     * @return array Messages indexed by rooms
     */
    protected function getMessages(array $config, array $rooms, string $lastMessageIdsFile)
    {
        $token = $config['token'];
        $timezone = $config['timezone'];

        $auth = new OAuth2($token);
        $client = new Client($auth);
        $roomAPI = new RoomAPI($client);

        $result = [];
        $lastMessageIds = json_decode(file_get_contents($lastMessageIdsFile), true) ?: [];
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
            $dstRoom = $rooms[$srcRoom];

            $prevFrom = '';
            $prevTimestamp = 0;
            foreach ($roomMessages as $message) {
                $from = $message->getFrom();
                $date = $message->getDate();
                $timestamp = strtotime($date) ?: 0;
                $text = $message->getMessage();

                // Hipchat creates an additional message per link in messages, ignored here
                if ('Link' === $from) {
                    continue;
                }

                // Heading for text - do not add if prev msg from same person within short timespan
                if ($from != $prevFrom || ($timestamp - $prevTimestamp) > 300) {
                    $text = sprintf("*[From Hipchat, %s, %s, %s]*\n\n%s",
                        $srcRoom,
                        $from,
                        $date,
                        $text
                    );
                }

                // Store prev info and create main payload
                $prevFrom = $from;
                $prevTimestamp = $timestamp;
                $data = [
                    'text' => $text,
                    'channel' => $dstRoom,
                    'username' => $username,
                    'icon_emoji' => $iconEmoji,
                ];

                // Attachment
                $file = $message->getFile();
                if ($file) {
                    $name = $file->getName();
                    $url = $file->getUrl();
                    $thumbUrl = $file->getThumbUrl();

                    if ($thumbUrl) {
                        // File is an image having a thumbnail
                        $attachment = [
                            'title' => $name,
                            'image_url' => $url,
                            'thumb_url' => $file->getThumbUrl(),
                        ];
                    } else {
                        // Show size of attachment in title
                        $attachment = [
                            'title' => $name . ' (' . $file->getSize() . ' B)',
                            'title_link' => $url,
                        ];
                    }

                    $data['attachments'] = [$attachment];
                }

                curl_setopt($handler, CURLOPT_POSTFIELDS, json_encode($data));
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
