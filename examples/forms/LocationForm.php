<?php
declare(strict_types=1);
namespace FloCMS\Reactive\Examples;

use FloCMS\Core\{App, Auth, HttpException, Session, Validator, ValidationException};
use FloCMS\Core\Http\Request;
use FloCMS\Reactive\{Component, Attributes\Action, Attributes\State};

/** One address per authenticated user; owner identity never comes from the browser. */
final class LocationForm extends Component
{
    // Length/required checks run on save so incomplete drafts remain editable.
    #[State]
    public string $name = '';
    #[State(rules: 'integer|min:0|max:1000000')]
    public int $countryId = 0;
    #[State(rules: 'integer|min:0|max:1000000')]
    public int $cityId = 0;
    #[State(writable: false)]
    public bool $saved = false;

    public function authorize(Request $request): void
    {
        Auth::authorize('locations.view');
        $this->ownerId();
    }

    public function mount(): void
    {
        $record = App::db()->table('delivery_addresses')->where('user_id', '=', $this->ownerId())->first();
        if ($record !== null) {
            $this->name = (string) $record->name;
            $this->countryId = (int) $record->country_id;
            $this->cityId = (int) $record->city_id;
        }
    }

    public function updated(string $property): void
    {
        $this->saved = false;
        if ($property === 'countryId') { $this->cityId = 0; }
    }

    /** Allow incomplete drafts, but reject forged relationships on every refresh. */
    public function validate(): array
    {
        $labels = $this->labels();
        $errors = [];
        if ($this->countryId !== 0 && !App::db()->table('countries')->where('id', '=', $this->countryId)->first()) {
            $errors['countryId'] = $labels['invalid_country'];
        }
        if ($this->cityId !== 0 && !App::db()->table('cities')->where('id', '=', $this->cityId)->where('country_id', '=', $this->countryId)->first()) {
            $errors['cityId'] = $labels['invalid_city'];
        }
        return $errors;
    }

    #[Action(permission: 'locations.edit')]
    public function save(): void
    {
        Auth::authorize('locations.edit');
        $owner = $this->ownerId();
        $labels = $this->labels();
        $this->name = trim($this->name);
        App::db()->transaction(function () use ($owner, $labels): void {
            // Validate again inside the transaction against current lookup rows.
            $errors = $this->validate();
            try {
                Validator::validate(['name' => $this->name], ['name' => 'required|string|max:100']);
            } catch (ValidationException $error) {
                $errors['name'] = $this->name === '' ? $labels['required_name'] : $labels['long_name'];
            }
            if ($this->countryId === 0) { $errors['countryId'] = $labels['required_country']; }
            if ($this->cityId === 0) { $errors['cityId'] = $labels['required_city']; }
            if ($errors !== []) { throw new ValidationException($errors); }

            $data = ['name' => $this->name, 'country_id' => $this->countryId, 'city_id' => $this->cityId];
            if (App::db()->table('delivery_addresses')->where('user_id', '=', $owner)->first()) {
                App::db()->table('delivery_addresses')->where('user_id', '=', $owner)->update($data);
            } else {
                App::db()->table('delivery_addresses')->insert(['user_id' => $owner] + $data);
            }
        });
        $this->saved = true;
    }

    public function render(): string
    {
        return $this->view(__DIR__ . '/location.html', [
            'name' => $this->name, 'countryId' => $this->countryId, 'cityId' => $this->cityId,
            'saved' => $this->saved, 'canSave' => Auth::can('locations.edit'), 'labels' => $this->labels(),
            'countries' => App::db()->table('countries')->orderBy('id')->get(),
            'cities' => App::db()->table('cities')->where('country_id', '=', $this->countryId)->orderBy('id')->get(),
        ]);
    }

    private function ownerId(): int
    {
        $id = (int) Session::get('user_id', 0);
        if ($id < 1) { throw new HttpException(401); }
        return $id;
    }

    private function labels(): array
    {
        $language = defined('ACTIVE_LANG') ? ACTIVE_LANG : 'en';
        return require __DIR__ . '/lang/' . (in_array($language, ['en', 'ku', 'ar'], true) ? $language : 'en') . '.php';
    }
}
