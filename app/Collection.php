<?php

namespace YesWikiRepo;

abstract class Collection implements \ArrayAccess, \Iterator
{
    public $elements;

    public function __construct()
    {
        $this->elements = array();
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->elements[] = $value;
            return;
        }
        $this->elements[$offset] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->elements[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->elements[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return isset($this->elements[$offset]) ? $this->elements[$offset] : null;
    }

    #[\ReturnTypeWillChange]
    public function rewind()
    {
        return reset($this->elements);
    }

    #[\ReturnTypeWillChange]
    public function current()
    {
        return current($this->elements);
    }

    #[\ReturnTypeWillChange]
    public function key()
    {
        return key($this->elements);
    }

    #[\ReturnTypeWillChange]
    public function valid()
    {
        return isset($this->elements[$this->key()]);
    }

    #[\ReturnTypeWillChange]
    public function next()
    {
        return next($this->elements);
    }
}
