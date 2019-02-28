<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Throwable;

class RepositoryNotFoundException extends ShopwareHttpException
{
    public function __construct(string $entity, int $code = 0, Throwable $previous = null)
    {
        $message = sprintf('Repository for entity "%s" does not exist.', $entity);

        parent::__construct($message, $code, $previous);
    }
}
