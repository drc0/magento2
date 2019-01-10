<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\AuthorizenetAcceptjs\Gateway\Validator;

use Magento\AuthorizenetAcceptjs\Gateway\Config;
use Magento\AuthorizenetAcceptjs\Gateway\SubjectReader;
use Magento\Framework\Encryption\Helper\Security;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;

/**
 * Validates the transaction hash
 */
class TransactionHashValidator extends AbstractValidator
{
    /**
     * The error code for failed transaction hash verification
     */
    const ERROR_TRANSACTION_HASH = 'ETHV';

    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param ResultInterfaceFactory $resultFactory
     * @param SubjectReader $subjectReader
     * @param Config $config
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        SubjectReader $subjectReader,
        Config $config
    ) {
        parent::__construct($resultFactory);

        $this->subjectReader = $subjectReader;
        $this->config = $config;
    }

    /**
     * Validates the transaction hash matches the configured hash
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject)
    {
        $response = $this->subjectReader->readResponse($validationSubject);

        if (!empty($response['transactionResponse']['transHashSHA2'])) {
            return $this->validateSha512Hash($validationSubject);
        } elseif (!empty($response['transactionResponse']['transHash'])) {
            return $this->validateMd5Hash($validationSubject);
        }

        return $this->createResult(
            false,
            [
                __('The authenticity of the gateway response could not be verified.')
            ],
            [self::ERROR_TRANSACTION_HASH]
        );
    }

    /**
     * Validates the response again the legacy MD5 spec
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    private function validateMd5Hash(array $validationSubject)
    {
        $response = $this->subjectReader->readResponse($validationSubject);
        $storedHash = $this->config->getLegacyTransactionHash($this->subjectReader->readStoreId($validationSubject));
        $hash = $this->generateMd5Hash(
            $storedHash,
            $this->subjectReader->readLoginId($validationSubject),
            $this->subjectReader->readAmount($validationSubject),
            $response['transactionResponse']['transId'] ?? ''
        );

        if (Security::compareStrings($hash, $response['transactionResponse']['transHash'])) {
            return $this->createResult(true);
        }

        return $this->createResult(
            false,
            [
                __('The authenticity of the gateway response could not be verified.')
            ],
            [self::ERROR_TRANSACTION_HASH]
        );
    }

    /**
     * Validates the response against the new SHA-512 spec
     *
     * @param array $validationSubject
     * @return ResultInterface
     */
    private function validateSha512Hash(array $validationSubject)
    {
        $response = $this->subjectReader->readResponse($validationSubject);
        $storedKey = $this->config->getTransactionSignatureKey($this->subjectReader->readStoreId($validationSubject));

        $hash = $this->generateSha512Hash(
            $storedKey,
            $this->subjectReader->readLoginId($validationSubject),
            $this->subjectReader->readAmount($validationSubject),
            $response['transactionResponse']['transId'] ?? ''
        );

        if (Security::compareStrings($hash, $response['transactionResponse']['transHashSHA2'])) {
            return $this->createResult(true);
        }

        return $this->createResult(
            false,
            [
                __('The authenticity of the gateway response could not be verified.')
            ],
            [self::ERROR_TRANSACTION_HASH]
        );
    }

    /**
     * Generates a Md5 hash to compare against AuthNet's.
     *
     * @param string $merchantMd5
     * @param string $merchantApiLogin
     * @param string $amount
     * @param string $transactionId
     * @return string
     */
    private function generateMd5Hash(
        $merchantMd5,
        $merchantApiLogin,
        $amount,
        $transactionId
    ) {
        return strtoupper(md5($merchantMd5 . $merchantApiLogin . $transactionId . $amount));
    }

    /**
     * Generates a SHA-512 hash to compare against AuthNet's.
     *
     * @param string $merchantKey
     * @param string $merchantApiLogin
     * @param string $amount
     * @param string $transactionId
     * @return string
     */
    private function generateSha512Hash(
        $merchantKey,
        $merchantApiLogin,
        $amount,
        $transactionId
    ) {
        $message = '^' . $merchantApiLogin . '^' . $transactionId . '^' . $amount . '^';

        return hash_hmac('sha512', $message, $merchantKey);
    }
}
