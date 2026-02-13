<?php

namespace WireElements\LivewireStrict\Features\SupportSignedActions;

use Livewire\Component;
use Livewire\Livewire;
use WireElements\LivewireStrict\Attributes\Signed;
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

    public function test_cant_call_signed_method_directly()
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
        })
            ->call('delete', 5);
    }

    public function test_can_call_signed_method_with_valid_signature()
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

        $payload = SupportSignedActions::generateSignedPayload(
            $component->instance()->getId(),
            'delete',
            5
        );

        $component->call('__callSigned', $payload)
            ->assertSet('result', 5);
    }

    public function test_cant_call_signed_method_with_tampered_params()
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

        // Generate valid payload then tamper with params
        $validPayload = SupportSignedActions::generateSignedPayload(
            $component->instance()->getId(),
            'delete',
            5
        );

        $decoded = json_decode(base64_decode($validPayload), true);
        $decoded['params'] = [999];
        $tamperedPayload = base64_encode(json_encode($decoded));

        $component->call('__callSigned', $tamperedPayload);
    }

    public function test_cant_call_signed_method_with_wrong_component_id()
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

        $payload = SupportSignedActions::generateSignedPayload(
            'wrong-component-id',
            'delete',
            5
        );

        $component->call('__callSigned', $payload);
    }

    public function test_can_call_non_signed_method_when_feature_enabled()
    {
        LivewireStrict::signedActions(components: 'WireElements\*');

        Livewire::test(new class extends TestSignedComponent
        {
            public function regularMethod()
            {
                $this->result = 'regular';
            }
        })
            ->call('regularMethod')
            ->assertSet('result', 'regular');
    }

    public function test_signed_methods_work_when_feature_disabled()
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

    public function test_only_enabled_for_matching_namespace()
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
        })
            ->call('delete', 5);
    }

    public function test_it_ignores_other_namespaces()
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
        })
            ->call('__callSigned', 'not-valid-base64-garbage');
    }

    public function test_rejects_signed_payload_on_component_without_signed_methods()
    {
        $this->expectException(InvalidSignedActionException::class);

        LivewireStrict::signedActions(components: 'WireElements\*');

        $component = Livewire::test(new class extends TestSignedComponent
        {
            public function regularMethod()
            {
                $this->result = 'should not run';
            }
        });

        // Craft a valid-signature payload targeting a non-signed method
        $payload = SupportSignedActions::generateSignedPayload(
            $component->instance()->getId(),
            'regularMethod'
        );

        $component->call('__callSigned', $payload);
    }

    public function test_valid_payload_with_ttl_succeeds_before_expiry()
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

        $payload = SupportSignedActions::generateSignedPayload(
            $component->instance()->getId(),
            'delete',
            5
        );

        $component->call('__callSigned', $payload)
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

        $payload = SupportSignedActions::generateSignedPayload(
            $component->instance()->getId(),
            'delete',
            5
        );

        // Travel forward in time past the TTL
        $this->travel(301)->seconds();

        $component->call('__callSigned', $payload);
    }

    public function test_payload_without_ttl_does_not_expire()
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

        $payload = SupportSignedActions::generateSignedPayload(
            $component->instance()->getId(),
            'delete',
            5
        );

        // Travel far forward — no TTL means no expiration
        $this->travel(7)->days();

        $component->call('__callSigned', $payload)
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

        $payload = SupportSignedActions::generateSignedPayload(
            $component->instance()->getId(),
            'delete',
            5
        );

        // Tamper with expiry to extend it
        $decoded = json_decode(base64_decode($payload), true);
        $decoded['exp'] = time() + 99999;
        $tamperedPayload = base64_encode(json_encode($decoded));

        $this->travel(120)->seconds();

        $component->call('__callSigned', $tamperedPayload);
    }

    public function test_per_method_ttl_overrides_global_ttl()
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

        $ttl = SupportSignedActions::getMethodTtl($component->instance(), 'delete');
        $payload = SupportSignedActions::generateSignedPayloadWithTtl(
            $ttl,
            $component->instance()->getId(),
            'delete',
            5
        );

        // 61 seconds - past per-method TTL of 60, but within global TTL of 300
        $this->travel(61)->seconds();

        $this->expectException(ExpiredSignedActionException::class);
        $component->call('__callSigned', $payload);
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

        $ttl = SupportSignedActions::getMethodTtl($component->instance(), 'delete');
        $payload = SupportSignedActions::generateSignedPayloadWithTtl(
            $ttl,
            $component->instance()->getId(),
            'delete',
            5
        );

        // 30 seconds - within per-method TTL of 60
        $this->travel(30)->seconds();

        $component->call('__callSigned', $payload)
            ->assertSet('result', 5);
    }

    public function test_method_without_per_method_ttl_uses_global()
    {
        LivewireStrict::signedActions(components: 'WireElements\*', ttl: 120);

        $component = Livewire::test(new class extends TestSignedComponent
        {
            #[Signed]
            public function delete(int $id)
            {
                $this->result = $id;
            }
        });

        $ttl = SupportSignedActions::getMethodTtl($component->instance(), 'delete');
        $payload = SupportSignedActions::generateSignedPayloadWithTtl(
            $ttl,
            $component->instance()->getId(),
            'delete',
            5
        );

        // 121 seconds - past global TTL of 120
        $this->travel(121)->seconds();

        $this->expectException(ExpiredSignedActionException::class);
        $component->call('__callSigned', $payload);
    }

    public function test_per_method_ttl_zero_disables_expiration_even_with_global_ttl()
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

        $ttl = SupportSignedActions::getMethodTtl($component->instance(), 'delete');
        $this->assertNull($ttl, 'ttl: 0 should resolve to null (no expiration)');

        $payload = SupportSignedActions::generateSignedPayloadWithTtl(
            $ttl,
            $component->instance()->getId(),
            'delete',
            5
        );

        // Travel far into the future — should still work because ttl: 0 means no expiration
        $this->travel(9999)->seconds();

        $component->call('__callSigned', $payload)
            ->assertSet('result', 5);
    }

    public function test_signed_method_with_no_parameters_works()
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

        $payload = SupportSignedActions::generateSignedPayload(
            $component->instance()->getId(),
            'archive'
        );

        $component->call('__callSigned', $payload)
            ->assertSet('result', 'archived');
    }
}

class TestSignedComponent extends Component
{
    public $result = null;

    public function render()
    {
        return '<div></div>';
    }
}

class SpecificSignedComponent extends TestSignedComponent
{
}
