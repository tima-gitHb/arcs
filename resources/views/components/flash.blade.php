@if (session('success') || session('error') || session('info') || session('status'))
    <div class="space-y-0">
        @if (session('success'))
            <div class="border-b px-4 py-3 text-sm sm:px-6" style="background: var(--arcs-success-bg); color: var(--arcs-success); border-color: #B7DFC4;" role="status" data-arcs-flash="success">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="border-b bg-red-50 px-4 py-3 text-sm sm:px-6" style="color: var(--arcs-error); border-color: #F5C2C0;" role="alert" data-arcs-flash="error">
                {{ session('error') }}
            </div>
        @endif
        @if (session('info') || session('status'))
            <div class="border-b border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 sm:px-6" role="status" data-arcs-flash="info">
                {{ session('info') ?? session('status') }}
            </div>
        @endif
    </div>
@endif
