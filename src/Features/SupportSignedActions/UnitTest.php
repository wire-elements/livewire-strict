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

    public function test_rejects_callSigned_with_no_params()
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
        })->call('__callSigned');
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

    // ──────────────────────────────────────────────────────────
    //  Security: cross-system signature confusion
    // ──────────────────────────────────────────────────────────

    public function test_raw_app_key_hmac_does_not_validate_as_signed_payload()
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

        // Simulate a signature forged with the raw APP_KEY (no domain separation).
        // This must NOT be accepted by verify().
        $payloadData = [
            'id' => $component->instance()->getId(),
            'method' => 'delete',
            'params' => [5],
        ];
        $rawSig = hash_hmac(
            'sha256',
            json_encode($payloadData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            config('app.key')
        );
        $forged = base64_encode(json_encode(array_merge($payloadData, ['sig' => $rawSig])));

        $component->call('__callSigned', $forged);
    }

    // ──────────────────────────────────────────────────────────
    //  Security: payload with extra/unexpected fields
    // ──────────────────────────────────────────────────────────

    public function test_extra_fields_in_payload_are_silently_ignored()
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

        // Take a valid payload and inject an extra field (e.g., "admin": true).
        // verify() re-derives the HMAC from only the canonical fields (id, method,
        // params, exp), so the injected field is discarded. The sig still matches
        // and the method executes. This is acceptable because the extra field is
        // never used, but reviewers should be aware that additional JSON keys
        // don't invalidate the payload.
        $encoded = SignedPayload::forComponent($component->instance(), 'delete', 5)->encode();
        $decoded = json_decode(base64_decode($encoded), true);
        $decoded['admin'] = true;
        $tampered = base64_encode(json_encode($decoded));

        $component
            ->call('__callSigned', $tampered)
            ->assertSet('result', 5);
    }

    public function test_rejects_payload_with_empty_string_method()
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

        // Forge a payload with an empty method name. The HMAC is computed properly
        // but methodIsSigned('') should return false, blocking execution.
        $payload = new SignedPayload($component->instance()->getId(), '', [5]);
        $component->call('__callSigned', $payload->encode());
    }

    public function test_rejects_completely_empty_base64_payload()
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
        })->call('__callSigned', base64_encode(''));
    }

    public function test_rejects_payload_with_null_json_values()
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

        $payload = base64_encode(json_encode([
            'id' => null,
            'method' => null,
            'params' => null,
            'sig' => null,
        ]));

        $component->call('__callSigned', $payload);
    }

    public function test_different_app_keys_produce_different_signatures()
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

        $encoded1 = SignedPayload::forComponent($component->instance(), 'delete', 5)->encode();

        // Change the app key
        $originalKey = config('app.key');
        config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $encoded2 = SignedPayload::forComponent($component->instance(), 'delete', 5)->encode();

        // Restore original key
        config()->set('app.key', $originalKey);

        // Payloads signed with different keys must differ
        $this->assertNotSame($encoded1, $encoded2);

        // A payload signed with the wrong key must be rejected
        $this->expectException(InvalidSignedActionException::class);
        $component->call('__callSigned', $encoded2);
    }

    public function test_feature_can_be_disabled_at_runtime()
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

        // Direct call should be blocked while enabled
        try {
            $component->call('delete', 5);
            $this->fail('Expected InvalidSignedActionException');
        } catch (InvalidSignedActionException $e) {
            // expected
        }

        // Disabling at runtime bypasses all protection - flag for audit
        SupportSignedActions::$enabled = false;

        $component
            ->call('delete', 5)
            ->assertSet('result', 5);
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
