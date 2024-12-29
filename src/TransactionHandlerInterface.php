<?php

namespace Lemric\BatchRequest;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

interface TransactionHandlerInterface
{
    public function handleTransaction(Transaction $transaction, HttpKernelInterface $httpKernel): Response;
}