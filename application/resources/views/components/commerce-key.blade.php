@csrf
<input type="hidden" name="key" value="{{ old('key', (string) \Illuminate\Support\Str::uuid()) }}">

