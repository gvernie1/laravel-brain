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
        /** Declaration scope, retained as the backward-compatible sourceScope contract. */
        public string $sourceScope = '',
        /** More precise additive classification while the broad edge type remains compatible. */
        public string $subtype = '',
        /** 'application' | 'framework' | 'vendor' | 'runtime' | 'unknown'. */
        public string $receiverScope = '',
        /** 'application' | 'framework' | 'vendor' | 'runtime' | 'unknown'. */
        public string $declaringScope = '',
        public ?string $receiverFile = null,
        public ?string $declaringFile = null,
    ) {
        $this->receiverFqcn ??= $this->calleeFqcn;
        $this->declaringScope = $this->declaringScope !== '' ? $this->declaringScope : $this->sourceScope;
        $this->sourceScope = $this->sourceScope !== '' ? $this->sourceScope : $this->declaringScope;
    }
}
