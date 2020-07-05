<?php

declare(strict_types=1);

namespace Stopka\OpenviduPhpClient\Session;

use DateTimeImmutable;
use Stopka\OpenviduPhpClient\ApiPaths;
use Stopka\OpenviduPhpClient\InvalidDataException;
use Stopka\OpenviduPhpClient\MediaModeEnum;
use Stopka\OpenviduPhpClient\OpenViduException;
use Stopka\OpenviduPhpClient\Recording\RecordingLayoutEnum;
use Stopka\OpenviduPhpClient\Recording\RecordingModeEnum;
use Stopka\OpenviduPhpClient\Recording\RecordingOutputModeEnum;
use Stopka\OpenviduPhpClient\Rest\RestClient;
use Stopka\OpenviduPhpClient\Rest\RestClientException;
use Stopka\OpenviduPhpClient\Session\Token\TokenOptions;
use Stopka\OpenviduPhpClient\Session\Token\TokenOptionsBuilder;

class Session
{

    /**
     * @var RestClient
     */
    private RestClient $restClient;

    /**
     * @var string
     */
    private string $sessionId;

    /**
     * @var DateTimeImmutable
     */
    private DateTimeImmutable $createdAt;

    /**
     * @var SessionProperties
     */
    private SessionProperties $properties;

    /**
     * @var Connection[]
     */
    private array $activeConnections = [];

    /**
     * @var bool
     */
    private bool $recording = false;

    /**
     * @param  RestClient             $restClient
     * @param  SessionProperties|null $properties
     * @return self
     */
    public static function createFromProperties(RestClient $restClient, ?SessionProperties $properties = null): self
    {
        return new self($restClient, $properties);
    }

    /**
     * @param  RestClient $restClient
     * @param  mixed[]    $data
     * @return self
     * @throws InvalidDataException
     */
    public static function createFromArray(RestClient $restClient, array $data): self
    {
        $session = new self($restClient, null, $data['sessionId']);
        $session->resetSessionWithDataArray($data);

        return $session;
    }

    /**
     * Session constructor.
     *
     * @param  RestClient             $restClient
     * @param  null|SessionProperties $properties
     * @param  null|string            $sessionId
     * @throws OpenViduException
     */
    protected function __construct(
        RestClient $restClient,
        ?SessionProperties $properties = null,
        ?string $sessionId = null
    ) {
        $this->restClient = $restClient;
        $this->createdAt = new DateTimeImmutable();
        $this->properties = $properties ?? (new SessionPropertiesBuilder())->build();
        $this->sessionId = $this->retrieveSessionId($sessionId);
    }

    /**
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * @return DateTimeImmutable
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param  null|TokenOptions $tokenOptions
     * @return string
     * @throws OpenViduException
     * @throws InvalidDataException
     */
    public function generateToken(?TokenOptions $tokenOptions = null): string
    {
        $tokenOptions = $tokenOptions ?? (new TokenOptionsBuilder())->build();
        try {
            $data = [
                'session' => $this->getSessionId(),
                'role' => (string)$tokenOptions->getRole(),
                'data' => $tokenOptions->getData(),
            ];
            $kurentoOptions = $tokenOptions->getKurentoOptions();
            if (null !== $kurentoOptions) {
                $data['kurentoOptions'] = $kurentoOptions->getDataArray();
            }

            return $this->restClient->post(ApiPaths::TOKENS, $data)->getStringInArrayKey('id');
        } catch (RestClientException $e) {
            throw new OpenViduException('Could not retrieve token', 0, $e);
        }
    }

    /**
     *
     */
    public function close(): void
    {
        try {
            $this->restClient->delete(ApiPaths::SESSIONS . '/' . $this->sessionId);
        } catch (RestClientException $e) {
            throw new OpenViduException('Unable to close session', 0, $e);
        }
    }

    /**
     * @return bool hasChanged?
     * @throws InvalidDataException
     */
    public function fetch(): bool
    {
        try {
            $beforeArray = $this->toDataArray();
            $data = $this->restClient->get(ApiPaths::SESSIONS . '/' . $this->sessionId)
                ->getArray();
            $this->resetSessionWithDataArray($data);

            return json_encode($this->toDataArray()) !== json_encode($beforeArray);
        } catch (RestClientException $e) {
            throw new OpenViduException('Unable to fetch session', 0, $e);
        }
    }

    public function forceDisconnect(string $connectionId): void
    {
        try {
            $this->restClient->delete(ApiPaths::SESSIONS . '/' . $this->sessionId . '/connection/' . $connectionId);
            $connectionClosed = $this->activeConnections[$connectionId] ?? null;
            unset($this->activeConnections[$connectionId]);
            if (null !== $connectionClosed) {
                foreach ($connectionClosed->getPublishers() as $publisher) {
                    $streamId = $publisher->getStreamId();
                    foreach ($this->activeConnections as $connection) {
                        $subscribers = $connection->getSubscribers();
                        $subscribers = array_filter(
                            $subscribers,
                            static fn($subscriber) => $streamId !== $subscriber
                        );
                        $connection->setSubscribers($subscribers);
                    }
                }
            }

            // Remove every Publisher of the closed connection from every subscriber list of
            // other connections
        } catch (RestClientException $e) {
            throw new OpenViduException('Disconnecting failed', 0, $e);
        }
    }

    public function forceUnpublish(string $streamId): void
    {
        try {
            $this->restClient->delete(ApiPaths::SESSIONS . '/' . $this->sessionId . '/stream/' . $streamId);
        } catch (RestClientException $e) {
            throw new OpenViduException('Unpublishing failed', 0, $e);
        }
    }

    /**
     * @return Connection[]
     */
    public function getActiveConnections(): array
    {
        return $this->activeConnections;
    }

    public function isBeingRecorded(): bool
    {
        return $this->recording;
    }

    public function getProperties(): SessionProperties
    {
        return $this->properties;
    }

    public function __toString()
    {
        return $this->sessionId;
    }

    public function hasSessionId(): bool
    {
        return '' !== $this->sessionId;
    }

    /**
     * @param  string|null $sessionId
     * @return string
     * @throws OpenViduException
     */
    private function retrieveSessionId(?string $sessionId = null): string
    {
        if (null !== $sessionId && '' !== $sessionId) {
            return $sessionId;
        }
        try {
            return $this->restClient->post(
                ApiPaths::SESSIONS,
                [
                    'mediaMode' => (string)$this->properties->getMediaMode(),
                    'recordingMode' => (string)$this->properties->getRecordingMode(),
                    'defaultOutputMode' => (string)$this->properties->getDefaultOutputMode(),
                    'defaultRecordingLayout' => (string)$this->properties->getDefaultRecordingLayout(),
                    'defaultCustomLayout' => $this->properties->getDefaultCustomLayout(),
                    'customSessionId' => $this->properties->getCustomSessionId(),
                ]
            )->getStringInArrayKey('id');
        } catch (RestClientException $e) {
            throw new OpenViduException('Unable to generate a sessionId', 0, $e);
        }
    }

    public function setIsBeingRecorded(bool $recording): void
    {
        $this->recording = $recording;
    }

    /**
     * @param  mixed[] $data
     * @throws InvalidDataException
     */
    public function resetSessionWithDataArray(array $data): void
    {
        $this->sessionId = (string)$data['sessionId'];
        if (array_key_exists('createdAt', $data)) {
            $this->createdAt = $this->createdAt->setTimestamp((int)$data['createdAt']);
        }
        $this->recording = (bool)$data['recording'];
        $builder = new SessionPropertiesBuilder();
        $builder->setMediaMode(new MediaModeEnum($data['mediaMode']))
            ->setRecordingMode(new RecordingModeEnum($data['recordingMode']))
            ->setDefaultOutputMode(new RecordingOutputModeEnum($data['defaultOutputMode']));
        if (isset($data['defaultRecordingLayout'])) {
            $builder->setDefaultRecordingLayout(new RecordingLayoutEnum($data['defaultRecordingLayout']));
        }
        if (isset($data['defaultCustomLayout'])) {
            $builder->setDefaultCustomLayout($data['defaultCustomLayout']);
        }
        $customSessionId = $this->properties->getCustomSessionId();
        if ('' !== $customSessionId && null !== $customSessionId) {
            $builder->setCustomSessionId($this->properties->getCustomSessionId());
        }
        $this->properties = $builder->build();
        $this->activeConnections = [];
        foreach ($data['connections']['content'] as $arrayConnection) {
            $connection = Connection::createFromDataArray($arrayConnection);
            $this->activeConnections[$connection->getConnectionId()] = $connection;
        }
    }

    /**
     * @return mixed[]
     */
    public function toDataArray(): array
    {
        $connections = [];
        foreach ($this->activeConnections as $connection) {
            $connections[] = $connection->getDataArray();
        }

        return [
            'sessionId' => $this->sessionId,
            'createdAt' => $this->createdAt->getTimestamp(),
            'customSessionId' => $this->properties->getCustomSessionId(),
            'recording' => $this->recording,
            'mediaMode' => (string)$this->properties->getMediaMode(),
            'recordingMode' => (string)$this->properties->getRecordingMode(),
            'defaultOutputMode' => (string)$this->properties->getDefaultOutputMode(),
            'defaultRecordingLayout' => (string)$this->properties->getDefaultRecordingLayout(),
            'defaultCustomLayout' => $this->properties->getDefaultCustomLayout(),
            'connections' => [
                'numberOfElements' => count($connections),
                'content' => $connections,
            ],
        ];
    }
}
