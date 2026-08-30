<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Represents a single directed hop discovered during deep tracing.
 *
 * e.g.  OrderController::store  →  OrderService::createOrder  (type: service)
 *       OrderService::createOrder → OrderRepository::create   (type: repository)
 *       OrderRepository::create  → Order                      (type: model)
 *       OrderService::createOrder → SendOrderConfirmationJob  (type: job)
 */
class CallChainEdge
{
    public function __construct(
        public string $callerFqcn,
        public string $callerMethod,
        public string $calleeFqcn,
        public string $calleeMethod,
        /** 'service' | 'repository' | 'model' | 'job' | 'event' | 'action' | 'view' | 'mail' | 'notification' | 'enum' | 'interface' | 'trait' | 'abstract_class' */
        public string $type,
        /** 'public' | 'protected' | 'private' */
        public string $visibility = 'public',
        /** Runtime/declared receiver type at the call site. Defaults to calleeFqcn. */
        public ?string $receiverFqcn = null,
        /** Class/interface that actually declares calleeMethod, when statically knowable. */
        public ?string $declaringFqcn = null,
        /** Semantic kind of the declaring artifact (service, controller, form_request, framework, exception, …). */
        public string $ownerKind = '',
        /** 'application' | 'framework' | 'vendor' | 'runtime' | 'unknown'. */
        public string $sourceScope = '',
        /** More precise additive classification while the broad edge type remains compatible. */
        public string $subtype = '',
    ) {
        $this->receiverFqcn ??= $this->calleeFqcn;
        $this->declaringFqcn ??= $this->calleeFqcn;
    }
}
