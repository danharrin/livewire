<?php

namespace Livewire\Mechanisms\HandleRequests;

use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Livewire\Livewire;

class ComponentLifecycleBrowserTest extends \Tests\BrowserTestCase
{
    public static function tweakApplicationHook()
    {
        return function () {
            Route::get('/lifecycle-away', LifecycleCounter::class)->middleware('web');
        };
    }

    public function test_navigation_discards_an_in_flight_lazy_response_even_after_history_restores_the_id()
    {
        $browser = Livewire::visit([LifecycleLazyContainer::class, 'counter' => LifecycleLazyCounter::class]);

        $this->holdResponses($browser);

        $browser->script(<<<'JS'
            window.original = Livewire.all()[1];
            window.originalSnapshot = original.snapshotEncoded;
            original.el.scrollIntoView();
        JS);

        $browser->waitUntil('window.responses.length === 1')
            ->waitForNavigate()->click('@away')
            ->assertPathIs('/lifecycle-away')
            ->assertScript('original.el.__livewire === undefined', true)
            ->waitForNavigate()->back()
            ->assertScript('Livewire.find(original.id).__instance !== original', true);

        $browser->script('window.responses[0].release()');
        $browser->waitUntil('window.finished === 1')
            ->assertScript('original.snapshotEncoded === originalSnapshot', true)
            ->assertScript('Livewire.find(original.id).__instance.hasBeenLazyLoaded', false);

        $browser->script('Livewire.find(original.id).$el.scrollIntoView()');
        $browser->waitUntil('window.responses.length === 2');
        $browser->script('window.responses[1].release()');
        $browser->waitUntil('window.finished === 2')
            ->waitForTextIn('output', '37')
            ->assertScript('Livewire.find(original.id).__instance.hasBeenLazyLoaded', true)
            ->assertConsoleLogHasNoErrors();
    }

    public function test_a_destroyed_commit_settles_callers_without_applying_effects_or_disrupting_its_pool()
    {
        $browser = Livewire::visit([LifecycleContainer::class, 'counter' => LifecycleCounter::class]);

        $this->assertDiscardedResponse($browser);
    }

    public function test_a_response_belongs_to_an_instance_not_a_reused_element_or_component_id()
    {
        $browser = Livewire::visit([LifecycleContainer::class, 'counter' => LifecycleCounter::class]);

        $this->assertDiscardedResponse($browser, restore: true);
    }

    protected function assertDiscardedResponse($browser, $restore = false)
    {
        $this->holdResponses($browser);

        $browser->script(<<<'JS'
            window.original = Livewire.all()[1];
            window.sibling = Livewire.all()[2];
            window.originalSnapshot = original.snapshotEncoded;
            window.events = [];
            window.dispatched = 0;
            window.addEventListener('saved', () => window.dispatched++);
            Livewire.hook('commit', ({ component, respond, succeed }) => {
                if (component !== original) return;
                respond(() => events.push('respond'));
                succeed(() => events.push('succeed'));
                return () => events.push('finish');
            });
            original.$wire.save(7).then(value => window.firstReturn = value);
            original.$wire.save(11).then(value => window.secondReturn = value);
            original.$wire.$commit().then(() => window.committed = true);
            sibling.$wire.save(23).then(value => window.siblingReturn = value);
        JS);

        $browser->waitUntil('window.responses.length === 1')
            ->assertScript('window.responses[0].components.length', 2);

        $browser->script('Alpine.destroyTree(original.el)');

        if ($restore) {
            // Reusing the actual root tests identity, not just the presence of `__livewire`.
            $browser->script('Alpine.initTree(original.el)');
            $browser->assertScript('original.el.__livewire.id === original.id', true)
                ->assertScript('original.el.__livewire !== original', true);
        } else {
            $browser->script('original.el.remove()');
        }

        $browser->script('window.responses[0].release()');

        $browser->waitUntil('window.committed === true && window.siblingReturn === 23')
            ->assertScript('window.firstReturn', 7)
            ->assertScript('window.secondReturn', 11)
            ->assertScript('window.events', ['respond'])
            ->assertScript('original.snapshotEncoded === originalSnapshot', true)
            ->assertScript('original.canonical.count', 0)
            ->assertScript('window.dispatched', 1)
            ->waitUntil('sibling.el.querySelector("output").textContent === "23"');

        if ($restore) {
            $browser->assertScript('original.el.__livewire.canonical.count', 0)
                ->assertScript('original.el.querySelector("output").textContent', '0');
        }

        // A surviving component must remain usable after the discarded response.
        $browser->script('sibling.$wire.save(31).then(value => window.nextReturn = value)');
        $browser->waitUntil('window.responses.length === 2');
        $browser->script('window.responses[1].release()');
        $browser->waitUntil('window.nextReturn === 31')
            ->waitUntil('sibling.el.querySelector("output").textContent === "31"')
            ->assertConsoleLogHasNoErrors();
    }

    public function test_a_component_destroyed_after_effects_does_not_run_its_queued_morph()
    {
        $browser = Livewire::visit(LifecycleCounter::class);

        $browser->script(<<<'JS'
            window.original = Livewire.first().__instance;
            window.morphs = 0;
            Livewire.hook('morph', () => window.morphs++);
            Livewire.hook('effect', ({ component, effects }) => {
                if (! effects.html) return;
                queueMicrotask(() => {
                    Alpine.destroyTree(component.el);
                    Alpine.initTree(component.el);
                });
            });
            original.$wire.save(13).then(value => window.returned = value);
        JS);

        $browser->waitUntil('window.returned === 13')
            ->assertScript('original.canonical.count', 13)
            ->assertScript('original.el.__livewire !== original', true)
            ->assertScript('original.el.__livewire.canonical.count', 0)
            ->assertSeeIn('output', '0')
            ->assertScript('window.morphs', 0)
            ->assertConsoleLogHasNoErrors();
    }

    public function test_respond_cleanup_can_destroy_a_component_and_missing_returns_still_settle_callers()
    {
        $browser = Livewire::visit(LifecycleCounter::class);

        $browser->script(<<<'JS'
            window.original = Livewire.first().__instance;
            Livewire.hook('payload.intercept', ({ components }) => {
                delete components[0].effects.returns;
            });
            Livewire.hook('commit', ({ respond }) => {
                respond(() => Alpine.destroyTree(original.el));
            });
            original.$wire.save(17).then(value => window.returnedUndefined = value === undefined);
            original.$wire.$commit().then(() => window.committed = true);
        JS);

        $browser->waitUntil('window.returnedUndefined === true && window.committed === true')
            ->assertScript('original.canonical.count', 0)
            ->assertSeeIn('output', '0')
            ->assertConsoleLogHasNoErrors();
    }

    public function test_an_old_form_response_does_not_enable_a_restored_instances_form()
    {
        $browser = Livewire::visit(LifecycleCounter::class);

        $this->assertFormCleanupIsInstanceScoped($browser);
    }

    public function test_parent_form_cleanup_belongs_to_the_parent_instance()
    {
        $browser = Livewire::visit([LifecycleParentForm::class, 'form' => LifecycleChildForm::class]);

        $this->assertFormCleanupIsInstanceScoped($browser);
    }

    protected function assertFormCleanupIsInstanceScoped($browser)
    {
        $this->holdResponses($browser);

        $browser->script(<<<'JS'
            window.original = Livewire.first().__instance;
            window.restored = original.el.cloneNode(true);
        JS);

        $browser->click('@submit')
            ->waitUntil('window.responses.length === 1')
            ->assertDisabled('@submit')
            ->assertScript('document.querySelector("input").readOnly', true);

        $browser->script(<<<'JS'
            Alpine.mutateDom(() => {
                Alpine.destroyTree(original.el);
                original.el.replaceWith(restored);
                Alpine.initTree(restored);
            });
        JS);

        $browser->assertScript('restored.__livewire.id === original.id && restored.__livewire !== original', true)
            ->assertEnabled('@submit')
            ->click('@submit')
            ->waitUntil('window.responses.length === 2')
            ->assertDisabled('@submit');

        $browser->script('window.responses[0].release()');
        $browser->waitUntil('window.finished === 1')
            ->assertDisabled('@submit')
            ->assertScript('document.querySelector("input").readOnly', true)
            ->assertScript('original.el.querySelector("button").disabled', false)
            ->assertScript('original.el.querySelector("input").readOnly', false);

        $browser->script('window.responses[1].release()');
        $browser->waitUntil('window.finished === 2')
            ->assertEnabled('@submit')
            ->assertScript('document.querySelector("input").readOnly', false)
            ->assertConsoleLogHasNoErrors();
    }

    protected function holdResponses($browser)
    {
        // Hold the real response body, not the request: server actions have already completed.
        $browser->script(<<<'JS'
            window.responses = [];
            window.finished = 0;
            Livewire.hook('request', ({ respond, succeed }) => {
                respond(({ response }) => {
                    let read = response.text.bind(response);
                    response.text = async () => {
                        let content = await read();
                        await new Promise(release => {
                            responses.push({ release, components: JSON.parse(content).components });
                        });
                        return content;
                    };
                });
                succeed(() => window.finished++);
            });
        JS);
    }
}

class LifecycleContainer extends Component
{
    public function render()
    {
        return '<div><livewire:counter /><livewire:counter /></div>';
    }
}

class LifecycleCounter extends Component
{
    public $count = 0;

    public function save($count)
    {
        $this->count = $count;
        $this->dispatch('saved');

        return $count;
    }

    public function render()
    {
        return <<<'HTML'
            <div>
                <output>{{ $count }}</output>
                <form wire:submit="save(5)">
                    <input aria-label="Name">
                    <button dusk="submit" type="submit">Save</button>
                </form>
            </div>
        HTML;
    }
}

class LifecycleParentForm extends LifecycleCounter
{
    public function render()
    {
        return '<div><livewire:form /></div>';
    }
}

class LifecycleChildForm extends Component
{
    public function render()
    {
        return <<<'HTML'
            <form wire:submit="$parent.save(5)">
                <input aria-label="Name">
                <button dusk="submit" type="submit">Save</button>
            </form>
        HTML;
    }
}

class LifecycleLazyContainer extends Component
{
    public function render()
    {
        return <<<'HTML'
            <div>
                <a href="/lifecycle-away" wire:navigate dusk="away">Leave</a>
                <div style="height: 200vh"></div>
                <livewire:counter lazy />
            </div>
        HTML;
    }
}

class LifecycleLazyCounter extends LifecycleCounter
{
    public function mount()
    {
        $this->count = 37;
    }

    public function placeholder()
    {
        return '<div>Loading...</div>';
    }
}
