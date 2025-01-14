<?php
declare(strict_types=1);

namespace Flowpack\JobQueue\Doctrine\Queue;

/*
 * This file is part of the Flowpack.JobQueue.Doctrine package.
 *
 * (c) Contributors to the package
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\InvalidArgumentException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;

/**
 * A queue implementation using doctrine as the queue backend
 */
class DoctrineQueue implements QueueInterface
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var array
     */
    protected $options;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * Default timeout for message reserves, in seconds
     *
     * @var int
     */
    protected $defaultTimeout = 60;

    /**
     * Interval messages are looked up in waitAnd*(), in seconds
     *
     * @var int
     */
    protected $pollInterval = 1;

    /**
     * Name of the table to store queue messages. Defaults to "<name>_messages"
     *
     * @var string
     */
    protected $tableName;

    /**
     * @param string $name
     * @param array $options
     */
    public function __construct(string $name, array $options)
    {
        $this->name = $name;
        if (isset($options['defaultTimeout'])) {
            $this->defaultTimeout = (integer)$options['defaultTimeout'];
        }
        if (isset($options['pollInterval'])) {
            $this->pollInterval = (integer)$options['pollInterval'];
        }
        if (isset($options['tableName'])) {
            $this->tableName = $options['tableName'];
        } else {
            $this->tableName = 'flowpack_jobqueue_messages_' . $this->name;
        }
        $this->options = $options;
    }

    /**
     * @param EntityManagerInterface $doctrineEntityManager
     * @return void
     * @throws DBALException
     */
    public function injectDoctrineEntityManager(EntityManagerInterface $doctrineEntityManager): void
    {
        if (isset($this->options['backendOptions'])) {
            $this->connection = DriverManager::getConnection($this->options['backendOptions']);
        } else {
            $this->connection = $doctrineEntityManager->getConnection();
        }
    }

    /**
     * @inheritdoc
     * @throws DBALException
     */
    public function setUp(): void
    {
        switch ($this->connection->getDatabasePlatform()->getName()) {
            case 'sqlite':
                $createDatabaseStatement = "CREATE TABLE IF NOT EXISTS {$this->connection->quoteIdentifier($this->tableName)} (id INTEGER PRIMARY KEY AUTOINCREMENT, payload LONGTEXT NOT NULL, state VARCHAR(255) NOT NULL, failures INTEGER NOT NULL DEFAULT 0, scheduled TEXT DEFAULT NULL)";
                break;
            case 'postgresql':
                $createDatabaseStatement = "CREATE TABLE IF NOT EXISTS {$this->connection->quoteIdentifier($this->tableName)} (id SERIAL PRIMARY KEY, payload TEXT NOT NULL, state VARCHAR(255) NOT NULL, failures INTEGER NOT NULL DEFAULT 0, scheduled TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL)";
                break;
            default:
                $createDatabaseStatement = "CREATE TABLE IF NOT EXISTS {$this->connection->quoteIdentifier($this->tableName)} (id INTEGER PRIMARY KEY AUTO_INCREMENT, payload LONGTEXT NOT NULL, state VARCHAR(255) NOT NULL, failures INTEGER NOT NULL DEFAULT 0, scheduled DATETIME DEFAULT NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB";
        }
        $this->connection->exec($createDatabaseStatement);
        try {
            $this->connection->exec("CREATE INDEX state_scheduled ON {$this->connection->quoteIdentifier($this->tableName)} (state, scheduled)");
        } catch (DBALException $e) {
            // See https://dba.stackexchange.com/questions/24531/mysql-create-index-if-not-exists
        }
    }

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritdoc
     * @throws DBALException
     */
    public function submit($payload, array $options = []): string
    {
        if ($this->connection->getDatabasePlatform()->getName() === 'postgresql') {
            $insertStatement = $this->connection->prepare("INSERT INTO {$this->connection->quoteIdentifier($this->tableName)} (payload, state, scheduled) VALUES (:payload, 'ready', {$this->resolveScheduledQueryPart($options)}) RETURNING id");
            $result = $insertStatement->executeQuery(['payload' => json_encode($payload)]);
            return (string)$result->fetchOne();
        }

        $numberOfAffectedRows = (int)$this->connection->executeStatement("INSERT INTO {$this->connection->quoteIdentifier($this->tableName)} (payload, state, scheduled) VALUES (:payload, 'ready', {$this->resolveScheduledQueryPart($options)})", ['payload' => json_encode($payload)]);
        if ($numberOfAffectedRows !== 1) {
            return '';
        }
        return (string)$this->connection->lastInsertId();
    }

    /**
     * @inheritdoc
     * @throws DBALException
     */
    public function waitAndTake(?int $timeout = null): ?Message
    {
        $message = $this->reserveMessage($timeout);
        if ($message === null) {
            return null;
        }

        $numberOfDeletedRows = $this->connection->delete($this->connection->quoteIdentifier($this->tableName), ['id' => (integer)$message->getIdentifier()]);
        if ($numberOfDeletedRows !== 1) {
            // TODO error handling
            return null;
        }

        return $message;
    }

    /**
     * @inheritdoc
     * @throws DBALException
     */
    public function waitAndReserve(?int $timeout = null): ?Message
    {
        return $this->reserveMessage($timeout);
    }

    /**
     * @param int $timeout
     * @return Message
     * @throws DBALException
     */
    protected function reserveMessage(?int $timeout = null): ?Message
    {
        if ($timeout === null) {
            $timeout = $this->defaultTimeout;
        }
        $this->reconnectDatabaseConnection();

        $startTime = time();
        do {
            try {
                $row = $this->connection->fetchAssociative("SELECT * FROM {$this->connection->quoteIdentifier($this->tableName)} WHERE state = 'ready' AND {$this->getScheduledQueryConstraint()} ORDER BY id ASC LIMIT 1");
            } catch (TableNotFoundException $exception) {
                throw new \RuntimeException(sprintf('The queue table "%s" could not be found. Did you run ./flow queue:setup "%s"?', $this->tableName, $this->name), 1469117906, $exception);
            }
            if ($row !== false) {
                $numberOfUpdatedRows = (int)$this->connection->executeStatement("UPDATE {$this->connection->quoteIdentifier($this->tableName)} SET state = 'reserved' WHERE id = :id AND state = 'ready' AND {$this->getScheduledQueryConstraint()}", ['id' => (integer)$row['id']]);
                if ($numberOfUpdatedRows === 1) {
                    return $this->getMessageFromRow($row);
                }
            }
            if (time() - $startTime >= $timeout) {
                return null;
            }
            sleep($this->pollInterval);
        } while (true);
    }

    /**
     * @inheritdoc
     * @throws DBALException
     */
    public function release(string $messageId, array $options = []): void
    {
        $this->connection->executeStatement("UPDATE {$this->connection->quoteIdentifier($this->tableName)} SET state = 'ready', failures = failures + 1, scheduled = {$this->resolveScheduledQueryPart($options)} WHERE id = :id", ['id' => (integer)$messageId]);
    }

    /**
     * @inheritdoc
     */
    public function abort(string $messageId): void
    {
        $this->connection->update($this->connection->quoteIdentifier($this->tableName), ['state' => 'failed'], ['id' => (integer)$messageId]);
    }

    /**
     * @inheritdoc
     * @throws InvalidArgumentException
     */
    public function finish(string $messageId): bool
    {
        return $this->connection->delete($this->connection->quoteIdentifier($this->tableName), ['id' => (integer)$messageId]) === 1;
    }

    /**
     * @inheritdoc
     */
    public function peek(int $limit = 1): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM {$this->connection->quoteIdentifier($this->tableName)} WHERE state = 'ready' AND {$this->getScheduledQueryConstraint()} ORDER BY id ASC LIMIT $limit");
        $messages = [];

        foreach ($rows as $row) {
            $messages[] = $this->getMessageFromRow($row);
        }

        return $messages;
    }

    /**
     * @inheritdoc
     */
    public function countReady(): int
    {
        return (integer)$this->connection->fetchOne("SELECT COUNT(*) FROM {$this->connection->quoteIdentifier($this->tableName)} WHERE state = 'ready'");
    }

    /**
     * @inheritdoc
     */
    public function countReserved(): int
    {
        return (integer)$this->connection->fetchOne("SELECT COUNT(*) FROM {$this->connection->quoteIdentifier($this->tableName)} WHERE state = 'reserved'");
    }

    /**
     * @inheritdoc
     */
    public function countFailed(): int
    {
        return (integer)$this->connection->fetchColumn("SELECT COUNT(*) FROM {$this->connection->quoteIdentifier($this->tableName)} WHERE state = 'failed'");
    }

    /**
     * @return void
     * @throws DBALException
     */
    public function flush(): void
    {
        $this->connection->executeStatement("DROP TABLE IF EXISTS {$this->connection->quoteIdentifier($this->tableName)}");
        $this->setUp();
    }

    /**
     * @param array $row
     * @return Message
     */
    protected function getMessageFromRow(array $row): Message
    {
        return new Message($row['id'], json_decode($row['payload'], true), (integer)$row['failures']);
    }

    /**
     * @param array $options
     * @return string
     */
    protected function resolveScheduledQueryPart(array $options): string
    {
        if (!isset($options['delay'])) {
            return 'null';
        }
        switch ($this->connection->getDatabasePlatform()->getName()) {
            case 'sqlite':
                return 'datetime(\'now\', \'+' . (integer)$options['delay'] . ' second\')';
            case 'postgresql':
                return 'NOW() + INTERVAL \'' . (integer)$options['delay'] . ' SECOND\'';
            default:
                return 'DATE_ADD(NOW(), INTERVAL ' . (integer)$options['delay'] . ' SECOND)';
        }
    }

    /**
     * @return string
     */
    protected function getScheduledQueryConstraint(): string
    {
        switch ($this->connection->getDatabasePlatform()->getName()) {
            case 'sqlite':
                return '(scheduled IS NULL OR scheduled <= datetime("now"))';
            default:
                return '(scheduled IS NULL OR scheduled <= NOW())';
        }
    }
    
    /**
     * Reconnects the database connection associated with this queue, if it doesn't respond to a ping
     *
     * @see \Neos\Flow\Persistence\Doctrine\PersistenceManager::persistAll()
     * @return void
     */
    private function reconnectDatabaseConnection(): void
    {
        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (\Exception $e) {
            $this->connection->close();
            $this->connection->connect();
        }
    }
}
