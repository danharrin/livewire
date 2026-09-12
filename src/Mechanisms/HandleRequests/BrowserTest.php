<?php

namespace Livewire\Mechanisms\HandleRequests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Livewire;

class BrowserTest extends \Tests\BrowserTestCase
{
    public static function tweakApplicationHook()
    {
        return function () {
            Route::get('/away', fn () => Blade::render('<html><body><h1>Another page</h1>@livewireScripts</body></html>'));
        };
    }

    public function test_can_register_a_custom_update_endpoint()
    {
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/custom/update', function () use ($handle) {
                $response = app(HandleRequests::class)->handleUpdate();

                // Override normal Livewire and force the updated count to be "5" instead of 2...
                $response['components'][0]['effects']['html'] = (string) str($response['components'][0]['effects']['html'])->replace(
                    '<span dusk="output">2</span>',
                    '<span dusk="output">5</span>'
                );

                return $response;
            })->name('custom');
        });

        Livewire::visit(new class extends \Livewire\Component {
            public $count = 1;
            function inc() { $this->count++; }
            function render() { return <<<'HTML'
            <div>
                <button wire:click="inc" dusk="target">+</button>
                <span dusk="output">{{ $count }}</span>
            </div>
            HTML; }
        })
        ->assertSeeIn('@output', 1)
        ->waitForLivewire()->click('@target')
        ->assertSeeIn('@output', 5)
        ;
    }

    public function test_a_lazy_response_does_not_update_a_page_restored_by_history()
    {
        $browser = Livewire::visit([new class extends Component {
            public function render() { return <<<'HTML'
                <div x-data="{ loaded: false }" x-on:account-loaded.window="loaded = true">
                    <a href="/away" wire:navigate dusk="away">Leave</a>
                    <p dusk="status" x-text="loaded ? 'Account loaded' : 'Waiting for account'"></p>
                    <div style="height: 200vh"></div>
                    <livewire:account lazy />
                </div>
            HTML; }
        }, 'account' => new class extends Component {
            public function mount() { $this->dispatch('account-loaded'); }
            public function placeholder() { return '<div dusk="account">Loading account...</div>'; }
            public function render() { return '<div dusk="account"><h2>Your account</h2><p>Account details</p></div>'; }
        }]);

        $this->holdUpdateResponses($browser);

        $browser->scrollIntoView('@account')->waitUntil('window.responses.length === 1')
            ->waitForNavigate()->click('@away')
            ->assertSee('Another page')
            ->waitForNavigate()->back()
            ->assertSeeIn('@status', 'Waiting for account');

        $browser->script('window.responses[0]()');
        $browser->waitUntil('window.delivered === 1')
            ->assertSeeIn('@status', 'Waiting for account')
            ->assertSeeIn('@account', 'Loading account...')
            ->scrollIntoView('@account')
            ->waitUntil('window.responses.length === 2');

        $browser->script('window.responses[1]()');
        $browser->waitUntil('window.delivered === 2')
            ->waitForTextIn('@status', 'Account loaded')
            ->assertSeeIn('@account', 'Account details')
            ->assertConsoleLogHasNoErrors();
    }

    public function test_a_removed_search_form_returns_its_result_without_updating_the_url()
    {
        $browser = Livewire::visit([new class extends Component {
            public $editing = true;
            public function render() { return <<<'HTML'
                <div x-data="{ receipt: 'Pending' }"
                    x-on:receipt.window="receipt = $event.detail.message">
                    <p dusk="receipt" x-text="receipt"></p>
                    <button wire:click="$set('editing', false)" dusk="close">Close search</button>
                    @if ($editing)
                        <livewire:editor />
                    @endif
                </div>
            HTML; }
        }, 'editor' => new class extends Component {
            #[Url(history: true)]
            public $query = '';

            public function search() { return 'Results for ' . $this->query; }

            public function render() { return <<<'HTML'
                <div dusk="editor">
                    <input wire:model="query" aria-label="Search" dusk="query">
                    <button x-on:click="$wire.search().then(message => window.dispatchEvent(new CustomEvent('receipt', { detail: { message } })))" dusk="search">Search</button>
                </div>
            HTML; }
        }]);

        $this->holdUpdateResponses($browser);

        $browser->type('@query', 'Taylor')->click('@search')->waitUntil('window.responses.length === 1');
        $browser->script('window.holdUpdates = false');
        $browser->waitForLivewire()->click('@close')->assertMissing('@editor');

        $browser->script('window.responses[0]()');
        $browser->waitUntil('window.delivered === 1')
            ->waitForTextIn('@receipt', 'Results for Taylor')
            ->assertQueryStringMissing('query')
            ->assertMissing('@editor')
            ->assertConsoleLogHasNoErrors();
    }

    public function test_a_component_can_remove_itself_with_js_after_an_action()
    {
        Livewire::visit([new class extends Component {
            public function render() { return '<div><h1>Profiles</h1><livewire:profile /></div>'; }
        }, 'profile' => new class extends Component {
            public $deleted = false;
            public function delete()
            {
                $this->deleted = true;
                $this->js('$el.remove()');
            }
            public function render() { return <<<'HTML'
                <div dusk="profile">
                    <button wire:click="delete" dusk="delete">Delete profile</button>
                    @if ($deleted)
                        <p>Profile deleted</p>
                    @endif
                </div>
            HTML; }
        }])
            ->waitForLivewire()->click('@delete')
            ->assertSee('Profiles')
            ->assertMissing('@profile')
            ->assertConsoleLogHasNoErrors();
    }

    public function test_an_old_submission_does_not_enable_a_form_restored_by_history()
    {
        $browser = Livewire::visit([SaveProfile::class, 'profile-form' => ProfileForm::class]);

        $this->assertRestoredFormStaysDisabled($browser);
    }

    public function test_an_old_parent_submission_does_not_enable_a_form_restored_by_history()
    {
        $browser = Livewire::visit([SaveProfile::class, 'profile-form' => ProfileForm::class], ['useParent' => true]);

        $this->assertRestoredFormStaysDisabled($browser);
    }

    protected function assertRestoredFormStaysDisabled($browser)
    {
        $this->holdUpdateResponses($browser);

        $browser->click('@edit')->type('@name', 'First submission')->click('@submit')
            ->waitUntil('window.responses.length === 1')
            ->assertDisabled('@submit')
            ->waitForNavigate()->click('@away')
            ->assertSee('Another page')
            ->waitForNavigate()->back()
            ->click('@edit')
            ->assertEnabled('@submit')
            ->type('@name', 'Second submission')->click('@submit')
            ->waitUntil('window.responses.length === 2')
            ->assertDisabled('@submit');

        $browser->script('window.responses[0]()');
        $browser->waitUntil('window.delivered === 1')
            ->assertDisabled('@submit')
            ->assertScript('document.querySelector("[dusk=name]").readOnly', true)
            ->assertInputValue('@name', 'Second submission');

        $browser->script('window.responses[1]()');
        $browser->waitUntil('window.delivered === 2')
            ->assertEnabled('@submit')
            ->assertScript('document.querySelector("[dusk=name]").readOnly', false)
            ->assertSeeIn('@status', 'Profile saved')
            ->assertConsoleLogHasNoErrors();
    }

    protected function holdUpdateResponses($browser)
    {
        // Delay delivery of real HTTP responses without changing their contents.
        $browser->script(<<<'JS'
            window.responses = [];
            window.delivered = 0;
            window.holdUpdates = true;
            const originalFetch = window.fetch;
            window.fetch = async (...parameters) => {
                const shouldHold = window.holdUpdates && parameters[1]?.method === 'POST';
                const response = await originalFetch(...parameters);
                if (! shouldHold) return response;

                const read = response.text.bind(response);
                response.text = async () => {
                    const content = await read();
                    await new Promise(resolve => window.responses.push(resolve));
                    setTimeout(() => window.delivered++, 0);
                    return content;
                };
                return response;
            };
        JS);
    }
}

class SaveProfile extends Component
{
    public $useParent = false;

    public function save() { $this->dispatch('profile-saved'); }

    public function render() { return <<<'HTML'
        <div x-data="{ saved: false }" x-on:profile-saved.window="saved = true">
            <a href="/away" wire:navigate dusk="away">Leave</a>
            <p dusk="status" x-text="saved ? 'Profile saved' : 'Edit profile'"></p>
            <livewire:profile-form :use-parent="$useParent" />
        </div>
    HTML; }
}

class ProfileForm extends Component
{
    public $useParent = false;
    public $name = '';

    public function save() { $this->dispatch('profile-saved'); }

    public function render() { return <<<'HTML'
        <div x-data="{ editing: false }">
            <button x-on:click="editing = true" dusk="edit">Edit profile</button>
            <template x-if="editing">
                <form wire:submit="{{ $useParent ? '$parent.save' : 'save' }}">
                    <input wire:model="name" aria-label="Name" dusk="name">
                    <button type="submit" dusk="submit">Save profile</button>
                </form>
            </template>
        </div>
    HTML; }
}
