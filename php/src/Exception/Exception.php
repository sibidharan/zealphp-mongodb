<?php

namespace ZealPHP\MongoDB\Exception;

interface ExceptionInterface extends \Throwable {}

class Exception extends \Exception implements ExceptionInterface {}
