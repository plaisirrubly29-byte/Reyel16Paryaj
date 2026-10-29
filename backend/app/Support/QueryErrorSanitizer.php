<?php

namespace App\Support;

use Exception;
use Illuminate\Database\QueryException;
use PDOException;
use ReflectionProperty;

/**
 * Retire des erreurs SQL la ligne « DETAIL » de PostgreSQL avant qu'elles ne soient journalisées ou rendues
 * (revue P8-02, M5). mask_bindings_in_exception_messages ne masque que la partie « SQL: » ; PostgreSQL ajoute
 * lui-même les valeurs refusées : « Key (phone)=(…) already exists » (doublon) ou toute la ligne
 * « Failing row contains (…, nom, téléphone, email, hash…) » (NOT NULL, CHECK).
 *
 * Branché par $exceptions->map() dans bootstrap/app.php : le code applicatif voit toujours l'exception d'origine
 * (même classe, même SQLSTATE), seuls les logs et la réponse reçoivent la version nettoyée.
 */
final class QueryErrorSanitizer
{
    public static function sanitize(QueryException $e): QueryException
    {
        $previous = $e->getPrevious();
        if (! $previous instanceof PDOException || ! self::hasDetail($previous->getMessage())) {
            return $e;
        }

        $clean = new PDOException(self::strip($previous->getMessage()));
        $errorInfo = $previous->errorInfo;
        if (is_array($errorInfo) && isset($errorInfo[2]) && is_string($errorInfo[2])) {
            $errorInfo[2] = self::strip($errorInfo[2]);
        }
        $clean->errorInfo = $errorInfo;
        // Le SQLSTATE (ex. « 23505 ») est une chaîne : le constructeur de PDOException n'accepte qu'un entier.
        (new ReflectionProperty(Exception::class, 'code'))->setValue($clean, $previous->getCode());

        $class = $e::class; // UniqueConstraintViolationException reste UniqueConstraintViolationException

        return new $class($e->getConnectionName(), $e->getSql(), $e->getBindings(), $clean, $e->getConnectionDetails(), $e->readWriteType, true);
    }

    private static function hasDetail(string $message): bool
    {
        return preg_match('/^DETAIL:/m', $message) === 1;
    }

    private static function strip(string $message): string
    {
        return (string) preg_replace('/\R?^DETAIL:.*$/m', '', $message);
    }
}
