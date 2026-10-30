<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use DateTimeImmutable;
use Kirby\Cms\App;
use Kirby\Http\Response;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\RequestErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Http\ResponseNegotiator;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use Throwable;

/** @internal Adapts browser-authorized Checkout creation to negotiated JSON or hosted HTML responses. */
final class CheckoutEndpoint
{
    public const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Vary' => 'Accept',
        'Referrer-Policy' => 'no-referrer',
    ];

    private const HTTP_OK = 200;
    private const HTTP_CREATED = 201;
    private const HTTP_SEE_OTHER = 303;
    private const HTTP_NOT_ACCEPTABLE = 406;

    public function __construct(private readonly App $kirby) {}

    public function respond(): Response
    {
        // Reject unsupported representations before body parsing, browser binding and creation work.
        $responseType = ResponseNegotiator::preferred($this->kirby, ['application/json', 'text/html']);

        if ($responseType === null) {
            return new Response('', code: self::HTTP_NOT_ACCEPTABLE, headers: self::HEADERS);
        }

        $authorization = null;
        $submission = null;
        $htmlResponse = $responseType === 'text/html';
        $acceptedAt = new DateTimeImmutable();
        $runtime = new RuntimeFactory($this->kirby);

        try {
            // Embedded Checkout has no HTML representation; reject it before parsing or creating anything.
            if ($htmlResponse && $runtime->settings()->uiMode() === UiMode::Embedded) {
                throw new CheckoutInputException(RequestErrorCode::UNSUPPORTED_REPRESENTATION);
            }

            // Content-Type owns request decoding independently from the negotiated response representation.
            $requestParser = new CheckoutHttpRequestParser($this->kirby);
            $input = CheckoutSubmissionParser::parse($this->kirby, $requestParser);
            $submissionFactory = $runtime->checkoutSubmissionFactory();
            // Retain the authorized attempt so later commerce/provider failures can safely return to its initiating page.
            $authorization = $submissionFactory->authorize($input, $acceptedAt);
            $submission = $submissionFactory->createAuthorized($authorization, $acceptedAt);
            $attempt = $submission->attempt();
            $presentation = $runtime->checkoutSessionCreator()->create(
                checkout: $submission->checkout(),
                shipping: $submission->shipping(),
                binding: $submission->binding(),
                token: $attempt->token(),
                guestReference: $attempt->context()->guestReference(),
                now: $acceptedAt,
                initiatingUrl: $attempt->initiatingUrl(),
            );

            if ($htmlResponse) {
                $redirectUrl = $presentation->redirectUrl();

                if ($redirectUrl === null) {
                    throw new \LogicException('Hosted Checkout requires a redirect URL.');
                }

                return $this->redirect($redirectUrl);
            }

            // The creator validates and authorizes presentation; project only the configured mode's ephemeral credential.
            $data = ['uiMode' => $presentation->uiMode()->value];

            if ($presentation->uiMode() === UiMode::Hosted) {
                $data['redirectUrl'] = $presentation->redirectUrl();
            } else {
                $data['clientSecret'] = $presentation->clientSecret();
            }

            return Response::json([
                'ok' => true,
                'data' => $data,
            ], code: $presentation->isReused() ? self::HTTP_OK : self::HTTP_CREATED, headers: self::HEADERS);
        } catch (Throwable $failure) {
            $httpFailure = (new CheckoutFailureMapper($this->kirby))->map($failure);

            if ($htmlResponse && $authorization !== null) {
                try {
                    $attempt = $authorization->attempt();
                    $retryInput = $submission !== null && $httpFailure->requiresExistingAction()
                        ? $submission->retryInput()
                        : null;
                    $reference = $runtime->checkoutFormResultStore()->save(
                        error: $httpFailure->error(),
                        attempt: $attempt,
                        retryInput: $retryInput,
                        savedAt: $acceptedAt,
                        expiresAt: BrowserAttemptStore::retainedUntil($attempt),
                    );
                    $location = (new CheckoutUrlBuilder($this->kirby))->formResultUrl($attempt->initiatingUrl(), $reference);

                    return $this->redirect($location);
                } catch (Throwable) {
                    // If native result storage fails, do not redirect without a retrievable error.
                }
            }

            if ($htmlResponse) {
                return new Response('', code: $httpFailure->httpStatus(), headers: self::HEADERS);
            }

            return Response::json([
                'ok' => false,
                'error' => $httpFailure->error()->toArray(),
            ], code: $httpFailure->httpStatus(), headers: self::HEADERS);
        }
    }

    private function redirect(string $location): Response
    {
        return new Response('', code: self::HTTP_SEE_OTHER, headers: [
            ...self::HEADERS,
            'Location' => $location,
        ]);
    }
}
