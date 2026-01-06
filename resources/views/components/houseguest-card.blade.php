@props(['houseguest'])

<div class="flex flex-col items-center gap-2 p-3 text-center">
    <div @class([$houseguest->is_active ? 'backdrop-contrast-200' : 'filter grayscale'])>
        <flux:avatar
            :src="$houseguest->avatar_url ? asset('storage/'.$houseguest->avatar_url) : null"
            :name="$houseguest->name"
            size="xl"
            class="size-32 shadow-sm"
        />

    </div>

    <div class="text-xs font-medium text-zinc-900 dark:text-zinc-100">{{ \Illuminate\Support\Str::title($houseguest->name) }}</div>
</div>
