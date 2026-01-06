@props(['houseguest'])

<div class="flex flex-col items-center gap-2 p-3 text-center">
    <div @class([$houseguest->is_active ? '' : 'filter grayscale'])>
        <flux:avatar
            :src="$houseguest->avatar_url ? asset('storage/'.$houseguest->avatar_url) : null"
            :name="$houseguest->name"
            {{-- size="xl" --}}
            class="size-16 md:size-24 xl:size-32 object-cover"
        />

    </div>

    <div class="text-xs font-medium text-zinc-900 dark:text-zinc-100">{{ \Illuminate\Support\Str::title($houseguest->name) }}</div>
</div>
