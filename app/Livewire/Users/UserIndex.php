<?php

namespace App\Livewire\Users;

use App\Actions\Users\ResetUserPassword;
use App\Actions\Users\SaveUser;
use App\Actions\Users\SetUserActive;
use App\Enums\Role;
use App\Livewire\Concerns\RunsActions;
use App\Livewire\Concerns\WithSorting;
use App\Models\User;
use App\Support\Kiln\CurrentKiln;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class UserIndex extends Component
{
    use RunsActions, WithPagination, WithSorting;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    // Create / edit form
    public bool $showForm = false;

    public ?int $editingId = null;

    /** @var array<string, string|null> */
    public array $form = [];

    // Reset password form
    public bool $showPasswordForm = false;

    public ?int $passwordUserId = null;

    /** @var array<string, string> */
    public array $passwordForm = ['password' => '', 'password_confirmation' => ''];

    // Activate / deactivate confirmation
    public bool $showStatusConfirm = false;

    public ?int $statusUserId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
        $this->form = $this->blankForm();
    }

    protected function sortableColumns(): array
    {
        return ['name', 'username', 'last_login_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return $this->kilnUsers()
            ->with('roles')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($this->status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy($this->activeSortField(), $this->activeSortDirection())
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function roleOptions(): array
    {
        $options = [];
        foreach (Role::cases() as $role) {
            if ($role === Role::Owner && ! $this->actor()->isOwner()) {
                continue;
            }
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    public function create(): void
    {
        $this->authorize('create', User::class);
        $this->resetErrorBag();
        $this->editingId = null;
        $this->form = $this->blankForm();
        $this->showForm = true;
    }

    public function edit(int $userId): void
    {
        $user = $this->findUser($userId);
        $this->authorize('update', $user);
        $this->resetErrorBag();

        $this->editingId = $user->id;
        $this->form = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'role' => $user->kilnRole()->value ?? Role::Viewer->value,
            'password' => '',
            'password_confirmation' => '',
        ];
        $this->showForm = true;
    }

    public function save(SaveUser $saveUser): void
    {
        $user = $this->editingId !== null ? $this->findUser($this->editingId) : null;

        $this->runAction(fn () => $saveUser->handle($this->actor(), $user, $this->form), 'form');

        $this->showForm = false;
        $this->toast($user ? __('Changes saved.') : __('User account created.'));
        unset($this->users);
    }

    public function openPasswordReset(int $userId): void
    {
        $user = $this->findUser($userId);
        $this->authorize('update', $user);
        $this->resetErrorBag();
        $this->passwordUserId = $user->id;
        $this->passwordForm = ['password' => '', 'password_confirmation' => ''];
        $this->showPasswordForm = true;
    }

    public function resetPassword(ResetUserPassword $resetUserPassword): void
    {
        $user = $this->findUser((int) $this->passwordUserId);

        $this->runAction(fn () => $resetUserPassword->handle($this->actor(), $user, $this->passwordForm), 'passwordForm');

        $this->showPasswordForm = false;
        $this->passwordForm = ['password' => '', 'password_confirmation' => ''];
        $this->toast(__('Password changed. :name has been logged out of all devices.', ['name' => $user->name]));
    }

    public function confirmStatusChange(int $userId): void
    {
        $user = $this->findUser($userId);
        $this->authorize('update', $user);
        $this->resetErrorBag();
        $this->statusUserId = $user->id;
        $this->showStatusConfirm = true;
    }

    public function changeStatus(SetUserActive $setUserActive): void
    {
        $user = $this->findUser((int) $this->statusUserId);
        $activate = ! $user->is_active;

        $this->runAction(fn () => $setUserActive->handle($this->actor(), $user, $activate));

        $this->showStatusConfirm = false;
        $this->toast($activate ? __(':name can log in again.', ['name' => $user->name]) : __(':name has been deactivated and logged out.', ['name' => $user->name]));
        unset($this->users);
    }

    #[Computed]
    public function statusUser(): ?User
    {
        return $this->statusUserId ? $this->findUser($this->statusUserId) : null;
    }

    #[Computed]
    public function passwordUser(): ?User
    {
        return $this->passwordUserId ? $this->findUser($this->passwordUserId) : null;
    }

    public function render(): View
    {
        return view('livewire.users.user-index', [
            'actor' => $this->actor(),
            'canManage' => $this->actor()->can('create', User::class),
        ])->title(__('Users'));
    }

    /**
     * @return Builder<User>
     */
    private function kilnUsers(): Builder
    {
        $kilnId = app(CurrentKiln::class)->id();

        return User::query()->whereHas('kilns', fn (Builder $q) => $q->whereKey($kilnId));
    }

    /** Users of other kilns are simply "not found". */
    private function findUser(int $userId): User
    {
        return $this->kilnUsers()->with('roles')->findOrFail($userId);
    }

    private function actor(): User
    {
        /** @var User */
        return auth()->user();
    }

    /**
     * @return array<string, string|null>
     */
    private function blankForm(): array
    {
        return [
            'name' => '',
            'username' => '',
            'email' => '',
            'phone' => '',
            'locale' => 'ur',
            'role' => Role::DataEntry->value,
            'password' => '',
            'password_confirmation' => '',
        ];
    }
}
