<?php

namespace WireElements\LivewireStrict\Features\SupportSignedActions;

use Livewire\Component;
use Livewire\Livewire;
use WireElements\LivewireStrict\Attributes\Signed;
use WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions\ExpiredSignedActionException;
use WireElements\LivewireStrict\Features\SupportSignedActions\Exceptions\InvalidSignedActionException;
use WireElements\LivewireStrict\LivewireStrict;

class UnitTest extends \Tests\TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        SupportSignedActions::$enabled = false;
        SupportSignedActions::$components = [];
        SupportSignedActions::$ttl = null;
    }

    // ──────────────────────────────────────────────────────────
    //  Core: signed methods cannot be called directly
    // ──────────────────────────────────────────────────────────

    public function test_blocks_direct_call_to_signed_method()
    {
        $this->expectException(InvalidSignedActionException::class);
        $this->expectExceptionMessage('Cannot call signed action: [delete]');

        LivewireStrict::signedActions(components: 'WireElements\*');

        Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        })->call('delete', 5);
    }

    public function test_allows_non_signed_methods()
    {
        LivewireStrict::signedActions(components: 'WireElements\*');

        Livewire::test(new class extends TestSignedComponent
        {
            public function save()
            {
                $this->result = 'saved';
            }
        })
            ->call('save')
            ->assertSet('result', 'saved');
    }

    public function test_signed_methods_work_normally_when_feature_disabled()
    {
        Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        })
            ->call('delete', 5)
            ->assertSet('result', 5);
    }

    // ──────────────────────────────────────────────────────────
    //  Core: valid signed payloads execute the method
    // ──────────────────────────────────────────────────────────

    public function test_executes_signed_method_with_valid_payload()
    {
        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        $component
            ->call('__callSigned', $payload->encode())
            ->assertSet('result', 5);
    }

    public function test_executes_signed_method_without_parameters()
    {
        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function archive()
            {
                $this->result = 'archived';
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'archive');

        $component
            ->call('__callSigned', $payload->encode())
            ->assertSet('result', 'archived');
    }

    // ──────────────────────────────────────────────────────────
    //  Security: tampered & invalid payloads
    // ──────────────────────────────────────────────────────────

    public function test_rejects_tampered_params()
    {
        $this->expectException(InvalidSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $encoded = SignedPayload::forComponent($component->instance(), 'delete', 5)->encode();

        $decoded = json_decode(base64_decode($encoded), true);
        $decoded['params'] = [999];
        $tampered = base64_encode(json_encode($decoded));

        $component->call('__callSigned', $tampered);
    }

    public function test_rejects_wrong_component_id()
    {
        $this->expectException(InvalidSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = (new SignedPayload('wrong-id', 'delete', [5]))->encode();

        $component->call('__callSigned', $payload);
    }

    public function test_rejects_malformed_payload()
    {
        $this->expectException(InvalidSignedActionException::class);
        $this->expectExceptionMessage('Cannot call signed action. The payload is invalid.');

        LivewireStrict::signedActions(components: 'WireElements\*');

        Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        })->call('__callSigned', 'not-valid-base64-garbage');
    }

    public function test_rejects_payload_targeting_non_signed_method()
    {
        $this->expectException(InvalidSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            public function save()
            {
                $this->result = 'should not run';
            }
        });

        $payload = (new SignedPayload($component->instance()->getId(), 'save'))->encode();

        $component->call('__callSigned', $payload);
    }

    // ──────────────────────────────────────────────────────────
    //  Component matching
    // ──────────────────────────────────────────────────────────

    public function test_enforces_for_matching_namespace()
    {
        $this->expectException(InvalidSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*');

        Livewire::test(new class extends SpecificSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        })->call('delete', 5);
    }

    public function test_ignores_non_matching_namespace()
    {
        LivewireStrict::signedActions(components: 'App\*');

        Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        })
            ->call('delete', 5)
            ->assertSet('result', 5);
    }

    // ──────────────────────────────────────────────────────────
    //  TTL: global expiration
    // ──────────────────────────────────────────────────────────

    public function test_payload_with_ttl_succeeds_before_expiry()
    {
        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 300);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        $component
            ->call('__callSigned', $payload->encode())
            ->assertSet('result', 5);
    }

    public function test_expired_payload_is_rejected()
    {
        $this->expectException(ExpiredSignedActionException::class);
        $this->expectExceptionMessage('Signed action [delete] has expired.');

        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 300);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        $this->travel(301)->seconds();

        $component->call('__callSigned', $payload->encode());
    }

    public function test_payload_without_ttl_never_expires()
    {
        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        $this->travel(7)->days();

        $component
            ->call('__callSigned', $payload->encode())
            ->assertSet('result', 5);
    }

    public function test_tampered_expiry_is_rejected()
    {
        $this->expectException(InvalidSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 60);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $encoded = SignedPayload::forComponent($component->instance(), 'delete', 5)->encode();

        $decoded = json_decode(base64_decode($encoded), true);
        $decoded['exp'] = time() + 99999;
        $tampered = base64_encode(json_encode($decoded));

        $this->travel(120)->seconds();

        $component->call('__callSigned', $tampered);
    }

    // ──────────────────────────────────────────────────────────
    //  TTL: per-method overrides
    // ──────────────────────────────────────────────────────────

    public function test_per_method_ttl_overrides_global()
    {
        $this->expectException(ExpiredSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 300);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed(ttl: 60)]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        // 61s past per-method TTL of 60, but within global TTL of 300
        $this->travel(61)->seconds();

        $component->call('__callSigned', $payload->encode());
    }

    public function test_per_method_ttl_succeeds_within_window()
    {
        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 300);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed(ttl: 60)]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        $this->travel(30)->seconds();

        $component
            ->call('__callSigned', $payload->encode())
            ->assertSet('result', 5);
    }

    public function test_method_without_per_method_ttl_uses_global()
    {
        $this->expectException(ExpiredSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 120);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        $this->travel(121)->seconds();

        $component->call('__callSigned', $payload->encode());
    }

    public function test_per_method_ttl_zero_disables_expiration()
    {
        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 60);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed(ttl: 0)]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        $this->travel(9999)->seconds();

        $component
            ->call('__callSigned', $payload->encode())
            ->assertSet('result', 5);
    }

    // ──────────────────────────────────────────────────────────
    //  TTL: validation
    // ──────────────────────────────────────────────────────────

    public function test_negative_global_ttl_is_rejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be a non-negative integer, got: -5');

        LivewireStrict::signedActions(components: 'WireElements\*', ttl: -5);
    }

    public function test_negative_per_method_ttl_is_rejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must be a non-negative integer, got: -10');

        new Signed(ttl: -10);
    }

    public function test_global_ttl_zero_disables_expiration()
    {
        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 0);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);

        $this->travel(9999)->seconds();

        $component
            ->call('__callSigned', $payload->encode())
            ->assertSet('result', 5);
    }

    // ──────────────────────────────────────────────────────────
    //  Edge cases
    // ──────────────────────────────────────────────────────────

    public function test_multiple_signed_methods_with_different_ttls()
    {
        $this->expectException(ExpiredSignedActionException::class);
        $this->expectExceptionMessage('Signed action [quickAction] has expired.');

        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 300);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed(ttl: 10)]
            public function quickAction()
            {
                $this->result = 'quick';
            }

            #[Signed(ttl: 600)]
            public function slowAction()
            {
                $this->result = 'slow';
            }
        });

        $quickPayload = SignedPayload::forComponent($component->instance(), 'quickAction');
        $slowPayload = SignedPayload::forComponent($component->instance(), 'slowAction');

        $this->travel(15)->seconds();

        // slowAction should still work (15s < 600s TTL)
        $component
            ->call('__callSigned', $slowPayload->encode())
            ->assertSet('result', 'slow');

        // quickAction should fail (15s > 10s TTL)
        $component->call('__callSigned', $quickPayload->encode());
    }

    public function test_rejects_callSigned_with_non_string_param()
    {
        $this->expectException(InvalidSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*');

        Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        })->call('__callSigned', 12345);
    }

    public function test_valid_payload_can_be_replayed()
    {
        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            public int $counter = 0;

            #[Signed]
            public function increment()
            {
                $this->counter++;
            }
        });

        $encoded = SignedPayload::forComponent($component->instance(), 'increment')->encode();

        $component
            ->call('__callSigned', $encoded)
            ->assertSet('counter', 1)
            ->call('__callSigned', $encoded)
            ->assertSet('counter', 2)
            ->call('__callSigned', $encoded)
            ->assertSet('counter', 3);
    }

    public function test_missing_app_key_throws_runtime_exception()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No application key set.');

        config()->set('app.key', null);

        (new SignedPayload('test-id', 'delete', [5]))->encode();
    }

    public function test_rejects_component_defining_callSigned_method()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('defines a __callSigned method');

        LivewireStrict::signedActions(components: 'WireElements\*');

        Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }

            public function __callSigned()
            {
                // This collides with the internal hook
            }
        })->call('__callSigned', 'anything');
    }

    // ──────────────────────────────────────────────────────────
    //  Type-invalid payloads (regression: should not cause TypeError)
    // ──────────────────────────────────────────────────────────

    public function test_rejects_payload_with_non_string_method()
    {
        $this->expectException(InvalidSignedActionException::class);
        $this->expectExceptionMessage('Cannot call signed action. The payload is invalid.');

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = base64_encode(json_encode([
            'id' => $component->instance()->getId(),
            'method' => ['not', 'a', 'string'],
            'params' => [5],
            'sig' => 'irrelevant',
        ]));

        $component->call('__callSigned', $payload);
    }

    public function test_rejects_payload_with_array_sig()
    {
        $this->expectException(InvalidSignedActionException::class);
        $this->expectExceptionMessage('Cannot call signed action. The payload is invalid.');

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = base64_encode(json_encode([
            'id' => $component->instance()->getId(),
            'method' => 'delete',
            'params' => [5],
            'sig' => ['not', 'a', 'string'],
        ]));

        $component->call('__callSigned', $payload);
    }

    public function test_rejects_payload_with_non_array_params()
    {
        $this->expectException(InvalidSignedActionException::class);
        $this->expectExceptionMessage('Cannot call signed action. The payload is invalid.');

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = base64_encode(json_encode([
            'id' => $component->instance()->getId(),
            'method' => 'delete',
            'params' => 'not-an-array',
            'sig' => 'irrelevant',
        ]));

        $component->call('__callSigned', $payload);
    }

    public function test_rejects_payload_with_non_int_exp()
    {
        $this->expectException(InvalidSignedActionException::class);
        $this->expectExceptionMessage('Cannot call signed action. The payload is invalid.');

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = base64_encode(json_encode([
            'id' => $component->instance()->getId(),
            'method' => 'delete',
            'params' => [5],
            'exp' => 'not-an-int',
            'sig' => 'irrelevant',
        ]));

        $component->call('__callSigned', $payload);
    }

    public function test_rejects_payload_with_non_scalar_id()
    {
        $this->expectException(InvalidSignedActionException::class);
        $this->expectExceptionMessage('Cannot call signed action. The payload is invalid.');

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = base64_encode(json_encode([
            'id' => ['an', 'array'],
            'method' => 'delete',
            'params' => [5],
            'sig' => 'irrelevant',
        ]));

        $component->call('__callSigned', $payload);
    }

    public function test_toAction_returns_wire_action_string()
    {
        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $payload = SignedPayload::forComponent($component->instance(), 'delete', 5);
        $action = $payload->toAction();

        $this->assertStringStartsWith("__callSigned('", $action);
        $this->assertStringEndsWith("')", $action);

        // The encoded payload inside should be verifiable
        $encoded = substr($action, strlen("__callSigned('"), -strlen("')"));
        $verified = SignedPayload::verify($encoded, $component->instance());

        $this->assertSame('delete', $verified->method);
        $this->assertSame([5], $verified->params);
    }
}

// ──────────────────────────────────────────────────────────
//  Test components
// ──────────────────────────────────────────────────────────

class TestSignedComponent extends Component
{
    public $result = null;

    public function render()
    {
        return '<div></div>';
    }
}

class SpecificSignedComponent extends TestSignedComponent {}
