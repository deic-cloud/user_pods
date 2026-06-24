<?php

declare(strict_types=1);

namespace OCA\UserPods\Exception;

/**
 * Thrown when a call to the sciencedata_kubernetes host service fails at the
 * TRANSPORT level — connection refused, timeout, DNS/TLS failure, a non-2xx
 * HTTP status, or NC's local-address SSRF block.
 *
 * This is deliberately distinct from a host *logical* error: the host service
 * signals those by returning HTTP 200 with a JSON body whose status is not
 * "success" (e.g. run_pod.php rejecting a manifest), which PodService passes
 * through unchanged so the frontend can show data.message/data.error. Only a
 * genuine "could not talk to the host" condition becomes this exception.
 *
 * The message is composed to be safe and useful to show to the user;
 * ApiController maps it to an HTTP 502 (Bad Gateway) error response.
 */
class PodHostException extends \RuntimeException {
}
