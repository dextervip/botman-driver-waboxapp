<?php

namespace BotMan\Drivers\Waboxapp;

use Illuminate\Support\Collection;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Users\User;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Outgoing\Question;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use BotMan\Drivers\Waboxapp\Exceptions\WaboxappException;
use BotMan\BotMan\Messages\Attachments\Location;
use Symfony\Component\HttpFoundation\ParameterBag;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\Drivers\Waboxapp\Exceptions\UnsupportedAttachmentException;

class WaboxappDriver extends HttpDriver
{
    protected $headers = [];

    const DRIVER_NAME = 'Waboxapp';

    const API_BASE_URL = 'https://www.waboxapp.com/api';

    /**
     * @param Request $request
     * @return void
     */
    public function buildPayload(Request $request)
    {
        parse_str($request->getContent(), $output);
        $this->payload = new ParameterBag($output ?? []);
        $this->headers = $request->headers->all();
        $this->event = Collection::make($this->payload);
        $this->config = Collection::make($this->config->get('waboxapp', []));
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {

        $matches = Collection::make(['event', 'token', 'uid', 'contact', 'message'])->diffAssoc($this->event->keys())->isEmpty();

        // catch only incoming messages
        return $matches && $this->event->get('message')['dir'] == 'i';
    }

    /**
     * Retrieve the chat message(s).
     *
     * @return array
     */
    public function getMessages()
    {
        $message = (array) $this->event->all();
            if (isset($message['message']['type']) && $message['message']['type'] == 'image') {
                $image = new Image($message['message']['body']['url'], $message);
                $image->title($message['message']['body']['caption']);

                $incomingMessage = new IncomingMessage(Image::PATTERN, $message['contact']['uid'], $message['uid'], $message);
                $incomingMessage->setImages([$image]);
//            } elseif (isset($message['stickerUrl'])) {
//                $sticker = new Image($message['stickerUrl'], $message);
//                $sticker->title($message['attribution']['name']);
//
//                $incomingMessage = new IncomingMessage(Image::PATTERN, $message['from'], $message['chatId'], $message);
//                $incomingMessage->setImages([$sticker]);
//            } elseif (isset($message['videoUrl'])) {
//                $incomingMessage = new IncomingMessage(Video::PATTERN, $message['from'], $message['chatId'], $message);
//                $incomingMessage->setVideos([new Video($message['videoUrl'], $message)]);
            } else {
                $incomingMessage = new IncomingMessage($message['message']['body']['text'], $message['contact']['uid'], $message['uid'], $message);
            }

            return [$incomingMessage];
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->config->get('token')) && !empty($this->config->get('uid'));
    }

    /**
     * Retrieve User information.
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        return new User($matchingMessage->getSender(), $this->payload->get('contact')['name'], null, $matchingMessage->getSender());
    }


    /**
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
     * @return Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * Convert a Question object into a valid message
     *
     *
     * @param \BotMan\BotMan\Messages\Outgoing\Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $buttons = $question->getButtons();
        if ($buttons) {
            $options =  Collection::make($buttons)->transform(function ($button) {
                return $button['value']. ' - '.$button['text'];
            })->toArray();

            return $question->getText() . "\nOptions: " . implode(', ', $options);
        }
    }

    /**
     * @param OutgoingMessage|\BotMan\BotMan\Messages\Outgoing\Question $message
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return array
     * @throws UnsupportedAttachmentException
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $payload = [
            'to' => $matchingMessage->getSender(),
            'uid' => $matchingMessage->getRecipient(),
        ];

        if ($message instanceof OutgoingMessage) {
            $attachment = $message->getAttachment();
            if ($attachment instanceof Image) {
                if (strtolower(pathinfo($attachment->getUrl(), PATHINFO_EXTENSION)) === 'gif') {
                    $payload['url'] = $attachment->getUrl();
                    $payload['type'] = 'video';
                    $payload['caption'] = $message->getText();
                } else {
                    $payload['url'] = $attachment->getUrl();
                    $payload['type'] = 'picture';
                    $payload['caption'] = $message->getText();
                }
            } elseif ($attachment instanceof Video) {
                $payload['url'] = $attachment->getUrl();
                $payload['type'] = 'video';
                $payload['caption'] = $message->getText();
            } elseif ($attachment instanceof Audio || $attachment instanceof Location || $attachment instanceof File) {
                throw new UnsupportedAttachmentException('The '.get_class($attachment).' is not supported (currently: Image, Video)');
            } else {
                $payload['text'] = $message->getText();
                $payload['type'] = 'text';
            }
        } elseif ($message instanceof Question) {
            $payload['text'] = $this->convertQuestion($message);
            $payload['type'] = 'text';
        }

        return $payload;
    }

    protected function getRequestCredentials()
    {
        return ['token' => $this->config->get('token'), 'uid' => $this->config->get('uid')];
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {

        $endpoint = null;

        switch ($payload['type']){
            case 'text':
                $endpoint = '/send/chat';
                break;
            case 'picture':
                $endpoint = '/send/image';
                break;
            case 'video':
                $endpoint = '/send/media';
                break;
            default:
                throw new \Exception('Payload type not implemented!');
        }

        return $this->http->post(self::API_BASE_URL . $endpoint, [],
            array_merge($payload, $this->getRequestCredentials()),
            ['Content-Type:application/json'],
            true);
    }

    /**
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return Response
     */
    public function types(IncomingMessage $matchingMessage)
    {
            // Do nothing
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $payload = array_merge_recursive([
            'to' => $matchingMessage->getRecipient(),
        ], $parameters);


        return $this->sendPayload($payload);
    }

}
