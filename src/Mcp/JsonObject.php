<?php
declare(strict_types = 1);

/**
 * Multicurl -- Object based asynchronous multi-curl wrapper
 *
 * Copyright (c) 2018-2025 Moritz Fain
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Maurice\Multicurl\Mcp;

/**
 * Wrapper for a decoded JSON object.
 *
 * Decoding JSON-RPC payloads as objects (instead of associative arrays) is
 * required to keep the distinction between JSON objects "{}" and arrays "[]"
 * and to preserve numeric-key objects like {"0":"x"}. To stay backward
 * compatible with the pre-3.0 associative-array accessors, this class behaves
 * like both an object ($obj->key) and an array ($obj['key'], foreach, isset,
 * count) while still re-encoding as a JSON object.
 *
 * The only behavioral difference compared to a plain associative array is the
 * type: inbound objects are now instances of JsonObject (and no longer match
 * `instanceof \stdClass` / `is_array()`).
 *
 * @author Moritz Fain <moritz@fain.io>
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class JsonObject implements \ArrayAccess, \IteratorAggregate, \JsonSerializable, \Countable
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * Recursively wrap a value decoded with json_decode($json, false).
     *
     * stdClass instances become JsonObject (preserving object shape), while
     * lists stay PHP arrays (their elements are wrapped recursively). Scalars
     * and null are returned unchanged.
     */
    public static function wrap(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $self = new self();
            /** @var mixed $item */
            foreach (get_object_vars($value) as $key => $item) {
                $self->data[(string)$key] = self::wrap($item);
            }

            return $self;
        }

        if (is_array($value)) {
            return array_map(self::wrap(...), $value);
        }

        return $value;
    }

    /**
     * Build a JsonObject from an associative array (values are stored as-is).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $self = new self();
        $self->data = $data;

        return $self;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[(string)$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[(string)$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new \LogicException('Cannot append to a JSON object');
        }

        $this->data[(string)$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[(string)$offset]);
    }

    /**
     * @return \ArrayIterator<string, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Re-encode as a JSON object, even when empty (so "{}" stays "{}").
     */
    public function jsonSerialize(): object
    {
        return (object)$this->data;
    }

    /**
     * Return the contained properties as an associative array (shallow).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
