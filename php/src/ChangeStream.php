<?php
namespace ZealPHP\MongoDB;
class ChangeStream implements \Iterator
{
    public function current(): mixed { return null; }
    public function key(): mixed { return null; }
    public function next(): void {}
    public function rewind(): void {}
    public function valid(): bool { return false; }
    public function getResumeToken(): ?object { return null; }
}
