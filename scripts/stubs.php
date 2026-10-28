<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for PSR packages so snippets that implement PSR interfaces
 * can run without Composer. Prepended by scripts/verify-snippets.php only.
 */

namespace Psr\Container {
    interface ContainerExceptionInterface extends \Throwable
    {
    }

    interface NotFoundExceptionInterface extends ContainerExceptionInterface
    {
    }

    interface ContainerInterface
    {
        public function get(string $id);

        public function has(string $id): bool;
    }
}
