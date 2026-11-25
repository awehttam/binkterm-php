<?php

namespace BinktermPHP;

/**
 * Thrown when a database connection cannot be established.
 *
 * Deliberately does not chain the underlying PDOException: its stack trace
 * contains the PDO constructor arguments, which include the DB password, and
 * uncaught-exception output prints previous exceptions.
 */
class DatabaseUnavailableException extends \RuntimeException
{
}
