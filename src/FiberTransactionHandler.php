<?php

namespace Lemric\BatchRequest;

use Fiber;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

class FiberTransactionHandler implements TransactionHandlerInterface
{
    /**
     * @throws Throwable
     */
    public function handleTransaction(Transaction $transaction, HttpKernelInterface $httpKernel): Response
    {
        $fiber = new Fiber(function() use ($transaction, $httpKernel) {
            return $transaction->handle($httpKernel);
        });

        $fiber->start();
        if ($fiber->isSuspended()) {
            $response = $fiber->resume();
        } elseif ($fiber->isTerminated()) {
            $response = $fiber->getReturn();
        } else {
            throw new \RuntimeException('Fiber is in an invalid state');
        }
        return $response;
    }
}