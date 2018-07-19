<?php

namespace Rudnev\Settings\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Rudnev\Settings\Contracts\StoreContract;

class DatabaseStore implements StoreContract
{
    /**
     * The settings store name
     *
     * @var string
     */
    protected $name;

    /**
     * The database connection instance.
     *
     * @var \Illuminate\Database\ConnectionInterface
     */
    protected $connection;

    /**
     * The name of the settings table.
     *
     * @var string
     */
    protected $table;

    /**
     * The name of the "key" column.
     *
     * @var string
     */
    protected $keyColumn;

    /**
     * The name of the "value" column.
     *
     * @var string
     */
    protected $valueColumn;

    /**
     * Create a new database store.
     *
     * @param  \Illuminate\Database\ConnectionInterface $connection
     * @param  string $table
     * @param  string $keyColumn
     * @param  string $valueColumn
     * @return void
     */
    public function __construct(ConnectionInterface $connection, $table, $keyColumn, $valueColumn)
    {
        $this->table = $table;
        $this->keyColumn = $keyColumn;
        $this->valueColumn = $valueColumn;
        $this->connection = $connection;
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @inheritDoc
     */
    public function has($key)
    {
        $keys = explode('.', $key);

        if (count($keys) > 1) {
            return (bool) $this->get($key);
        }

        return $this->table()->where($this->keyColumn, '=', $key)->exists();
    }

    /**
     * @inheritDoc
     */
    public function get($key)
    {
        $keys = explode('.', $key);

        if (count($keys) > 1) {
            $root = $keys[0];

            $data = [$root => $this->get($root)];

            return Arr::get($data, $key);
        }

        $item = $this->table()->where($this->keyColumn, '=', $key)->first();

        if (is_null($item)) {
            return;
        }

        $item = is_array($item) ? (object) $item : $item;

        return $this->unpack($item->{$this->valueColumn});
    }

    /**
     * @inheritDoc
     */
    public function getMultiple(iterable $keys)
    {
        $return = [];
        $data = [];

        foreach ($keys as $i => $key) {
            $subkeys = explode('.', $key);

            if (count($subkeys) > 1) {
                $root = $subkeys[0];

                if (! isset($data[$root])) {
                    $data[$root] = $this->get($root);
                }

                $return[$key] = Arr::get([$root => $data[$root]], $key);

                unset($keys[$i]);
            }
        }

        if (count($keys) === 0) {
            return $return;
        }

        $result = $this->table()->whereIn($this->keyColumn, $keys)->get();

        while ($item = $result->shift()) {
            $return[$item->{$this->keyColumn}] = $this->unpack($item->{$this->valueColumn});
        }

        foreach ($keys as $key) {
            if (! isset($return[$key])) {
                $return[$key] = null;
            }
        }

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function all()
    {
        $return = [];

        $result = $this->table()->get();

        while ($item = $result->shift()) {
            $return[$item->{$this->keyColumn}] = $this->unpack($item->{$this->valueColumn});
        }

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function set($key, $value)
    {
        list($key, $value) = $this->prepareIfNested($key, $value);

        $value = $this->pack($value);

        $this->table()->updateOrInsert([$this->keyColumn => $key], [$this->valueColumn => $value]);
    }

    /**
     * @inheritDoc
     */
    public function setMultiple(iterable $values)
    {
        foreach ($values as $key => $value) {
            list($key, $value) = $this->prepareIfNested($key, $value);
            $this->set($key, $value);
        }
    }

    /**
     * Prepare the item for setting it to the store,
     * if the key is a chain like a foo.bar.baz
     *
     * @param string $key
     * @param mixed $value
     * @return array
     */
    protected function prepareIfNested($key, $value)
    {
        $keys = explode('.', $key);

        if (count($keys) > 1) {
            $root = $keys[0];

            $data = [$root => $this->get($root)];

            Arr::set($data, $key, $value);

            return [$root, $data[$root]];
        }

        return [$key, $value];
    }

    /**
     * @inheritDoc
     */
    public function forget($key)
    {
        $keys = explode('.', $key);

        if (count($keys) > 1) {
            $root = $keys[0];

            $data = [$root => $this->get($root)];

            Arr::forget($data, [$key]);

            $this->set($root, $data[$root]);

            return true;
        }

        $this->table()->where($this->keyColumn, '=', $key)->delete();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function forgetMultiple(iterable $keys)
    {
        foreach ($keys as $key) {
            $this->forget($key);
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function flush()
    {
        return (bool) $this->table()->delete();
    }

    /**
     * Get a query builder for the settings table.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function table()
    {
        return $this->connection->table($this->table);
    }

    /**
     * Get the underlying database connection.
     *
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Pack the value before write to the database
     *
     * @param $value
     * @return string
     */
    protected function pack($value)
    {
        return json_encode($value);
    }

    /**
     * Unpack the value after retrieving then from the database
     *
     * @param $value
     * @return string
     */
    protected function unpack($value)
    {
        return json_decode($value, true);
    }
}