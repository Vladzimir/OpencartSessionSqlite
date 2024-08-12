<?php

namespace Session;

class Sqlite extends \SessionHandler
{
    private $pdo, $table;
    private $dbFilename = 'php_session.sqlite.db';

    public function __construct($registry)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('\'pdo_sqlite\' extension is needed to use this driver.');
        }

        register_shutdown_function('session_write_close');
    }

    public function open($path, $name)
    {
        if (!is_null($this->pdo)) {
            throw new \BadMethodCallException('Bad call to open(): connection already opened.');
        }

        if (!ctype_alnum($name)) {
            throw new \InvalidArgumentException('Invalid session name. Must be alphanumeric.');
        }

        if (false === realpath($path)) {
            mkdir($path, 0700, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            throw new \InvalidArgumentException('Invalid session save path.');
        }

        $dsn = 'sqlite:' . $path . DIRECTORY_SEPARATOR . $this->dbFilename;
        $this->table = strtolower($name);

        try {
            $this->pdo = new \PDO($dsn, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA temp_store=MEMORY;');
            $this->pdo->exec('PRAGMA synchronous=NORMAL;');
            $this->pdo->exec('PRAGMA mmap_size=268435456');

            $this->pdo->exec('PRAGMA secure_delete=0;');

            $this->pdo->query("SELECT 1 FROM `{$this->table}` LIMIT 1");
        } catch (\PDOException $e) {
            $this->pdo->exec(
                "CREATE TABLE `{$this->table}` (
                `id` TEXT PRIMARY KEY NOT NULL,
                `data` TEXT NOT NULL,
                `time` INTEGER NOT NULL
            ) WITHOUT ROWID;"
            );
            $this->pdo->exec("CREATE INDEX idx_time ON {$this->table} (time);");

            // Debug information
            throw new \RuntimeException("Error connecting to SQLite database or checking table: " . $e->getMessage());
        }

        return true;
    }

    public function close()
    {
        $this->pdo->exec('PRAGMA optimize;');
        $this->pdo = null;
        return true;
    }

    public function read($id)
    {
        $data = '';

        $sth = $this->pdo->prepare("SELECT data FROM {$this->table} WHERE id = :id LIMIT 1");
        $sth->bindParam(':id', $id, \PDO::PARAM_STR);

        if ($sth->execute()) {
            $result = $sth->fetch();
            $data = isset($result['data']) ? $result['data'] : '';
        } else {
            // Debug information
            throw new \RuntimeException("Failed to execute read statement for ID: {$id}");
        }

        $sth = null; // close
        return $data;
    }

    public function write($id, $data)
    {
        $sth = $this->pdo->prepare("REPLACE INTO {$this->table} (id, data, time) VALUES (:id, :data, :time)");
        $sth->bindParam(':id', $id, \PDO::PARAM_STR);
        $sth->bindValue(':data', $data, \PDO::PARAM_STR);
        $sth->bindValue(':time', time(), \PDO::PARAM_INT);

        $completed = $sth->execute();
        if (!$completed) {
            // Debug information
            throw new \RuntimeException("Failed to execute write statement for ID: {$id}");
        }

        $sth = null; // close
        return $completed;
    }

    public function destroy($id)
    {
        $sth = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id");
        $sth->bindParam(':id', $id, \PDO::PARAM_STR);

        $completed = $sth->execute();
        if (!$completed) {
            // Debug information
            throw new \RuntimeException("Failed to execute destroy statement for ID: {$id}");
        }

        $sth = null; // close
        return $completed;
    }

    public function gc($max_lifetime)
    {
        $sth = $this->pdo->prepare("DELETE FROM {$this->table} WHERE time < :time");
        $sth->bindValue(':time', time() - $max_lifetime, \PDO::PARAM_INT);

        $sth->execute();
        $count = $sth->rowCount();
        $sth = null; // close

        return $count;
    }
}