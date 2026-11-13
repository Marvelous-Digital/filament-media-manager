# Image Picker Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the ImagePicker field's one-card-per-variation layout with a header ⋯ menu, a strip of live crop thumbnails (Base first), one stage where the selected variation is reframed, and a per-variation toolbar.

**Architecture:** View-only rework of `resources/views/forms/components/image-picker.blade.php`, plus the hand-written stylesheet `resources/dist/filament-media-manager.css` and `media.picker.*` strings (en + fr). The Alpine component keeps its helpers and gains one UI-only property, `selected`. Per-variation markup (strip thumbnail, toolbar) is rendered server-side in a Blade loop, so Blade can still branch on saved conversion status and overrides; the toolbars are shown with `x-show="selected === '<name>'"`. No PHP, state, storage or crop-math changes.

**Tech Stack:** Laravel 13, Filament 5, Livewire 4, Alpine (bundled with Livewire), Pest 4. CSS is hand-written in `resources/dist/` (no build step).

**Spec:** `docs/superpowers/specs/2026-11-13-image-picker-layout-design.md`

## Global Constraints

- Do not change `src/Forms/Components/ImagePicker.php`, `ImagePickerState`, `PersistImagePicker`, migrations, `CropBox` or `GenerateConversion`. The only exception is when a task below says so, and none does.
- Keep these Livewire calls exactly as they are: `call('selectImage'|'storeUploadedImage'|'useMainImage'|'removeImage')`, `pick(target)`, `upload(target, files)`, and `mountAction('retryConversion', { conversion: id }, { schemaComponent: key })`.
- Keep the window listeners `x-on:image-picker:selected.window` and `x-on:image-picker:close.window` and the `wire:poll.3s` while a conversion is pending (tests pin them).
- Every new string goes in both `resources/lang/en/media.php` and `resources/lang/fr/media.php` under `picker`. Views contain no literal text (`tests/TranslationsTest.php` enforces this). Keys no longer used are removed from both files.
- Keep `wire:key`s per state path, and per state path + variation for per-variation elements: `fmm-thumb-{statePath}-{name}` and `fmm-toolbar-{statePath}-{name}`.
- Light and dark mode: use the existing `.dark .x` pattern of the stylesheet. For the selection ring, use Filament's `--primary-500` variable.
- Run tests with `vendor/bin/pest --no-coverage`. Before each commit, also run `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G`. CI requires all three.

## Review Focus

1. **A Livewire re-render keeps the selection:** `wire:poll.3s` and every Livewire call morph the field. An editor reframing the 2nd variation must not jump back to the first. Alpine keeps `x-data` across morphs while the root's `wire:key` is stable, so never put `selected` into a `@js()` value that changes between renders. Checked in Task 4's browser checklist.
2. **A variation with its own image of another shape:** the stage, its frame and the zoom limit come from that image (`sourceOf(name)`), not the base image. The thumbnail shows the own-image badge. Test in Task 2.
3. **A source without dimensions:** the strip and stage still render, with no frame on the stage (`cropRectStyle` hides it) and only the focus marker. Test in Task 2.
4. **Two ImagePicker fields in one form:** ids and `x-ref`s stay distinct per state path, so a drag in one never moves the other. Test in Task 2.
5. **No variations:** no strip, no toolbar, and the Alpine code never evaluates `state.variants[...]`. Test in Task 1.

---

### Task 1: Header menu and base stage

Replaces the current `fmm-picker-preview` block (lines 144-158: image plus Change / Upload new / Remove buttons) with the header row and a stage that shows the base image. The old variant cards stay in place for now. Task 3 removes them.

**Files:**
- Modify: `resources/views/forms/components/image-picker.blade.php` (Alpine data and the `@else` branch)
- Modify: `resources/dist/filament-media-manager.css`
- Modify: `resources/lang/en/media.php`, `resources/lang/fr/media.php`
- Test: `tests/ImagePickerViewTest.php`

**Interfaces:**
- Produces:
  - Alpine `selected: string|null`. `null` means Base. Its initial value is the first variation's name, or `null` when there are none: `selected: @js(array_key_first($variations))`.
  - Alpine `stageSource(): {url, width, height}|null`, which returns `this.selected === null ? this.media[this.state?.media_id] ?? null : this.sourceOf(this.selected)`.
  - Markup: `.fmm-picker-header` holding a Filament dropdown (`<x-filament::dropdown>` with an `<x-filament::icon-button icon="heroicon-m-ellipsis-horizontal">` trigger whose `label` is `picker.more_actions`). Then `.fmm-stage`, which contains `<img x-ref="stage" x-bind:src="stageSource()?.url">`.
  - Lang keys:
    - `more_actions`: 'More actions' / 'Plus d’actions'
    - `change_base`: 'Change base image' / 'Changer l’image de base'
    - `upload_new_base`: 'Upload new base image' / 'Téléverser une nouvelle image de base'
- Removed keys: `upload_new` (en + fr), if `grep -rn "picker.upload_new" src resources` finds nothing else. Keep `change`: the gallery picker uses it.

- [ ] **Step 1: Write the failing tests** in `tests/ImagePickerViewTest.php`. Replace the test "renders without variant cards when no variations are configured" with:

```php
it('shows the base image with its menu and no strip when no variations are configured', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create(['alt_text' => 'hi']);

    Livewire::test(NoVariationsRenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('Change base image')
        ->assertSee('Upload new base image')
        ->assertSee('Remove')
        ->assertSee('fmm-stage', escape: false)
        ->assertSee('Alt text')
        ->assertDontSee('fmm-strip', escape: false)
        ->assertDontSee('fmm-toolbar', escape: false)
        ->assertDontSee('Required formats');
});

it('puts the base image actions in the header menu', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('More actions')
        ->assertSee('Change base image')
        ->assertSee('Upload new base image')
        ->assertDontSee('Upload new<', escape: false);
});
```

Review Focus 5: the Alpine helpers may mention `state.variants`, but with no variations nothing may call them. The stage reads only `stageSource()`, which never touches `state.variants` while `selected` is `null`.

- [ ] **Step 2: Run the tests and see them fail**

Run: `vendor/bin/pest --no-coverage tests/ImagePickerViewTest.php`
Expected: the 2 new tests FAIL (no "Change base image").

- [ ] **Step 3: Implement.** In the Alpine data, add `selected` and `stageSource()` (see Interfaces). Replace the `fmm-picker-preview` block with:
  - The header row, with the dropdown items:
    - Change base image → `pick('main')`
    - Upload new base image → `$refs['upload-main'].click()`, disabled while `uploading`
    - Remove → `call('removeImage')`, danger colour
  - The uploading indicator next to the menu.
  - The stage: a plain image for now. Task 2 adds the frame and drag.

  CSS: `.fmm-picker-header` is a flex row with `justify-content: flex-end`. `.fmm-stage` is `position: relative; overflow: hidden; border-radius: .5rem; background` (light/dark, like `.fmm-focus-image`). `.fmm-stage img` is `display:block; width:100%; height:auto; max-height:24rem; object-fit:contain`. Delete the now-unused `.fmm-picker-preview` rules.

- [ ] **Step 4: Run the tests and see them pass**

Run: `vendor/bin/pest --no-coverage tests/ImagePickerViewTest.php tests/TranslationsTest.php tests/TranslationsRenderTest.php`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/forms/components/image-picker.blade.php resources/dist/filament-media-manager.css resources/lang tests/ImagePickerViewTest.php
git commit -m "feat(picker): header menu for the base image and a single stage"
```

### Task 2: Variation strip and reframing on the stage

Adds the strip above the stage (Base, then one thumbnail per variation) and moves frame drawing and dragging from the per-card reframe panel onto the stage, for the selected variation.

**Files:**
- Modify: `resources/views/forms/components/image-picker.blade.php`
- Modify: `resources/dist/filament-media-manager.css`
- Modify: `resources/lang/en/media.php`, `resources/lang/fr/media.php`
- Test: `tests/ImagePickerViewTest.php`

**Interfaces:**
- Consumes: `selected`, `stageSource()`, `.fmm-stage` with `x-ref="stage"` (Task 1).
- Produces:
  - `.fmm-strip`, a horizontally scrolling flex row with no wrap. In it, `<button type="button" class="fmm-thumb" data-fmm-thumb-base x-on:click="selected = null" x-bind:aria-pressed="selected === null">`, then per variation `<button type="button" class="fmm-thumb" data-fmm-thumb="{name}" wire:key="fmm-thumb-{statePath}-{name}" x-on:click="selected = '{name}'" x-bind:aria-pressed="selected === '{name}'">`.
  - A thumbnail's box is `.fmm-thumb-box` with `height: 3rem; aspect-ratio: {w} / {h}` holding the `previewStyle(name)` image, followed by `.fmm-thumb-name`. The Base box uses the base media's own ratio when it is known, and 1 / 1 otherwise.
  - Indicators, inside the box:
    - `.fmm-thumb-badge` (heroicon `photo`, `title` = `picker.own_image`), rendered by Blade when `$isOverridden`.
    - `.fmm-thumb-dot`, rendered by Blade when the conversion failed (`title` = `picker.conversion_failed`), plus a second dot with `x-cloak x-show="overZoomed('{name}')"` (`title` = `picker.over_zoomed`).
    - `.fmm-thumb-spinner` (`<x-filament::loading-indicator>`, `title` = `picker.generating`), rendered by Blade when pending.
  - Stage behaviour when `selected !== null`: the frame `<span class="fmm-stage-frame" x-show="selected !== null" x-bind:style="cropRectStyle(selected)">`, whose `box-shadow: 0 0 0 9999px rgb(0 0 0 / .45)` dims the outside, and the marker `<span class="fmm-focus-marker" x-show="selected !== null">`. Pointer events call `setFocus(selected, $event)`, guarded by `selected !== null`.
  - `setFocus(name, event)` now measures `this.$refs.stage`, not `$refs['img-' + name]`.
  - Lang keys:
    - `base`: 'Base' / 'Base'
    - `conversion_failed`: 'Generating this format failed' / 'La génération de ce format a échoué'
    - `over_zoomed`: 'Zoomed past the source resolution' / 'Zoom au-delà de la résolution de la source'

- [ ] **Step 1: Write the failing tests** in `tests/ImagePickerViewTest.php`.

```php
it('renders a strip with the base image first and a thumbnail per variation', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create();

    $html = Livewire::test(RenderHarness::class, ['preset' => $value->id])->html();

    expect($html)->toContain('data-fmm-thumb-base')
        ->and(strpos($html, 'data-fmm-thumb-base'))->toBeLessThan(strpos($html, 'data-fmm-thumb="desktop"'))
        ->and(strpos($html, 'data-fmm-thumb="desktop"'))->toBeLessThan(strpos($html, 'data-fmm-thumb="mobile"'))
        ->and($html)->toContain('aspect-ratio: 1500 / 900')
        ->toContain('Base')
        ->toContain('selected: \'desktop\'')
        ->toContain('fmm-stage-frame');
});

it('marks a failed variation with a dot and an overridden one with a badge, and no ready text', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'status' => ConversionStatus::Completed, 'media_id' => Media::factory()->state(['width' => 600, 'height' => 800])->create()->id]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('title="Generating this format failed"', escape: false)
        ->assertSee('title="Own image"', escape: false)
        ->assertDontSee('Ready');
});

it('shows a spinner on a variation that is still generating', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Pending]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('fmm-thumb-spinner', escape: false)
        ->assertSee('wire:poll', escape: false);
});

it('renders the strip and stage when the source has no dimensions', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => null, 'height' => null]), 'source')->create();

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('data-fmm-thumb="desktop"', escape: false)
        ->assertSee('fmm-stage', escape: false);
});

it('keeps two pickers on one form apart', function () {
    $a = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    $b = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();

    Livewire::test(TwoPickersRenderHarness::class, ['preset' => $a->id, 'second' => $b->id])
        ->assertSee('fmm-thumb-data.hero_image_id-desktop', escape: false)
        ->assertSee('fmm-thumb-data.card_image_id-desktop', escape: false);
});
```

  - In the "stops polling once every conversion is completed" test, keep `assertDontSee('Generating')`. That still holds: the spinner's title is only rendered while pending.
  - Add `TwoPickersRenderHarness extends RenderHarness` with `public ?int $second = null;`. Its `mount()` fills `['hero_image_id' => $this->preset, 'card_image_id' => $this->second]`, and its `form()` has two `ImagePicker::make(...)->variations(['desktop' => [1500, 900]])`.
  - Replace the old "renders variant cards when the source has no dimensions" test with the no-dimensions test above.
  - Review Focus 2 is covered by the overridden-variation test. Its stage behaviour is checked in Task 4.

- [ ] **Step 2: Run the tests and see them fail**

Run: `vendor/bin/pest --no-coverage tests/ImagePickerViewTest.php`
Expected: the new tests FAIL (no `data-fmm-thumb-base`).

- [ ] **Step 3: Implement** the strip, indicators, stage frame, marker and drag, as given under Interfaces. Remove the per-card thumbnail and the per-card `.fmm-reframe` panel (focus image, crop rectangle, marker) from the old cards, but leave the old cards' buttons, status and alt for Task 3. Change `setFocus` to measure `$refs.stage`.

  CSS: `.fmm-strip` is `display:flex; gap:.5rem; overflow-x:auto; padding-bottom:.25rem`. `.fmm-thumb` is a flex column with no shrink and an 11px name. `.fmm-thumb[aria-pressed=true] .fmm-thumb-box` gets `outline: 2px solid rgb(var(--primary-500)); outline-offset: 1px`. `.fmm-thumb-box` is `position:relative; overflow:hidden; border-radius:.375rem`. The badge, dot and spinner sit absolutely in its corners; the dot is 0.5rem, red (`rgb(239 68 68)`). `.fmm-stage` gets `cursor: crosshair; touch-action: none` only while a variation is selected (`x-bind:class="{ 'fmm-stage-editing': selected !== null }"`). `.fmm-stage-frame` is absolute, with a 2px white border and the dimming box-shadow. Reuse the existing `.fmm-focus-marker` rule. Delete the rules for removed classes (`.fmm-variant-thumb`, `.fmm-focus-image`, `.fmm-focus-crop-rect`, `.fmm-reframe`).

- [ ] **Step 4: Run the tests and see them pass**

Run: `vendor/bin/pest --no-coverage tests/ImagePickerViewTest.php tests/TranslationsTest.php tests/TranslationsRenderTest.php`
Expected: all PASS. Tests that still assert "Reframe" ("renders a card per variant…", "hydrates a stored id…", "renders a plain id…") are rewritten in Task 3. If they fail now, change only their "Reframe" assertion to `assertSee('data-fmm-thumb="desktop"', escape: false)`.

- [ ] **Step 5: Commit**

```bash
git add resources/views/forms/components/image-picker.blade.php resources/dist/filament-media-manager.css resources/lang tests/ImagePickerViewTest.php
git commit -m "feat(picker): variation strip with live crops, reframe on the stage"
```

### Task 3: Selected-variation toolbar, old cards removed

Replaces the remaining per-variation cards (`.fmm-variants`) with one toolbar per variation under the stage. Each is shown only while its variation is selected.

**Files:**
- Modify: `resources/views/forms/components/image-picker.blade.php`
- Modify: `resources/dist/filament-media-manager.css`
- Modify: `resources/lang/en/media.php`, `resources/lang/fr/media.php`
- Test: `tests/ImagePickerViewTest.php`

**Interfaces:**
- Consumes: `selected` (Task 1), the strip and stage (Task 2).
- Produces:
  - Per variation, `<div class="fmm-toolbar" data-fmm-toolbar="{name}" wire:key="fmm-toolbar-{statePath}-{name}" x-cloak x-show="selected === '{name}'">` placed between the stage and the base alt field. It contains, in order:
    1. The title `{name} · {Ratio::reduce(w, h)} · {w}×{h}`.
    2. The zoom range: the existing input and its value, moved here as is (`min=1 max=5 step=0.1`, `x-model.number="state.variants['{name}'].zoom"`, `x-on:input="touch()"`), plus the existing `zoom_warning` text under `x-show="overZoomed('{name}')"`.
    3. A Reset button that sets `focus_x = 0.5`, `focus_y = 0.5`, `zoom = 1` on `state.variants['{name}']`, then calls `touch()`.
    4. "Use another image for {name}" → `pick('{name}')`.
    5. "Upload for {name}" → `$refs['upload-{name}'].click()`. The hidden file input with `x-ref="upload-{name}"` moves into the toolbar.
    6. Only `@if ($isOverridden)`: "Back to base image" → `call('useMainImage', { variation: '{name}' })`, and the own alt input (existing `x-model="state.variants['{name}'].alt"`, id `fmm-alt-{statePath}-{name}`) labelled "Alt text for {name}".
    7. Only `@if` the conversion failed: Retry, the existing `retryConversion` `mountAction`.
  - Lang keys:
    - `reset`: 'Reset' / 'Réinitialiser'
    - `use_another_image_for`: 'Use another image for :name' / 'Utiliser une autre image pour :name'
    - `upload_for`: 'Upload for :name' / 'Téléverser pour :name'
    - `back_to_base`: 'Back to base image' / 'Revenir à l’image de base'
    - `alt_text_for`: 'Alt text for :name' / 'Texte alternatif pour :name'
  - Removed keys (en + fr): `reframe`, `use_different_image`, `upload_for_format`, `use_main_image`, `alt_text_for_format`, `ready`. Before removing each one, `grep -rn "picker.<key>" src resources tests` must find nothing else.

- [ ] **Step 1: Rewrite the tests** in `tests/ImagePickerViewTest.php`:
  - "renders a card per variant with reframe and override actions" becomes the first test below.
  - "shows own-image controls only for overridden variants" becomes the second.
  - In "hydrates a stored id…" and "renders a plain id…", `assertSee('Reframe')` becomes `assertSee('data-fmm-toolbar="desktop"', escape: false)`.

```php
it('renders a toolbar per variation with zoom, reset, another image, upload and retry', function () {
    $value = MediaFieldValue::factory()->for(Media::factory()->state(['width' => 2000, 'height' => 1000]), 'source')->create(['alt_text' => 'hi']);
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'desktop', 'status' => ConversionStatus::Failed]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertOk()
        ->assertSee('data-fmm-toolbar="desktop"', escape: false)
        ->assertSee('data-fmm-toolbar="mobile"', escape: false)
        ->assertSee('desktop · 5:3 · 1500×900')
        ->assertSee('Zoom')
        ->assertSee('Reset')
        ->assertSee('Use another image for desktop')
        ->assertSee('Upload for mobile')
        ->assertSee('Retry')
        ->assertSee('Alt text')
        ->assertDontSee('Reframe')
        ->assertDontSee('fmm-variant', escape: false);
});

it('shows back to base and its own alt only for a variation with its own image', function () {
    $value = MediaFieldValue::factory()->for(Media::factory(), 'source')->create();
    MediaConversion::factory()->for($value, 'value')->create(['variation' => 'mobile', 'media_id' => Media::factory()->create()->id]);

    Livewire::test(RenderHarness::class, ['preset' => $value->id])
        ->assertSee('Back to base image')
        ->assertSee('Alt text for mobile')
        ->assertDontSee('Alt text for desktop')
        ->assertDontSee('Retry');
});
```

- [ ] **Step 2: Run the tests and see them fail**

Run: `vendor/bin/pest --no-coverage tests/ImagePickerViewTest.php`
Expected: the 2 rewritten tests FAIL (no `data-fmm-toolbar`).

- [ ] **Step 3: Implement** the toolbars as given under Interfaces, and delete the whole `.fmm-variants` block. Order of the `@else` branch: header, strip, stage, toolbars, base alt field (`state.alt`, unchanged), unsaved notice (unchanged).

  CSS: `.fmm-toolbar` is a flex column with a `.5rem` gap. Its title is `font-weight:600; font-size:.875rem`. The controls row is a wrapping flex with a `.5rem` gap. Delete every `.fmm-variant*` rule and anything else this change left unused; grep each class in `resources/views` before deleting it.

  Remove the unused lang keys from both files.

- [ ] **Step 4: Run the full suite, lint and analysis**

Run: `vendor/bin/pest --no-coverage && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G`
Expected: all tests PASS (248+), pint passes, `[OK] No errors`.

- [ ] **Step 5: Commit**

```bash
git add resources/views/forms/components/image-picker.blade.php resources/dist/filament-media-manager.css resources/lang tests/ImagePickerViewTest.php
git commit -m "feat(picker): one toolbar for the selected variation; drop the variant cards"
```

### Task 4: Changelog and browser check

**Files:**
- Modify: `CHANGELOG.md` (under "Unreleased", created at the top if missing)

- [ ] **Step 1: Changelog entry.** Under "Unreleased", "Changed": "ImagePicker field: one stage with a strip of live crop thumbnails (Base first) replaces the per-variation cards. Click a thumbnail to reframe it on the stage; its toolbar holds zoom, Reset, Use another image / Upload for this format, Back to base image and Retry. Base image actions moved to a ⋯ menu. No API or stored-data change." Add a "Translations" line listing the added and removed `media.picker.*` keys.

- [ ] **Step 2: Commit**

```bash
git add CHANGELOG.md
git commit -m "docs: changelog for the image picker layout"
```

- [ ] **Step 3: Browser check, with the user.** Use a sandbox that runs this branch: `./fp new blade --demo=full --package filament-media-manager` from the workbench root, or an existing blade sandbox whose media-manager points at this checkout. Then run `./fp <sandbox> artisan filament:assets`, because the CSS is served from published copies. Use the media-manager driver and a page with a block that has an image field with 2+ variations. Checklist:
  1. The strip shows Base first, then each variation at its real shape, and the first variation is selected.
  2. Clicking a thumbnail moves the frame on the stage; dragging moves it; the thumbnail updates live.
  3. Zoom changes the frame; past the source resolution, the warning text and a red dot appear.
  4. Reset puts the frame back in the centre at 1×.
  5. "Use another image for X" picks from the library. The stage then shows that image, the thumbnail gets the badge, and "Back to base image" and its own alt field appear. Back to base removes them.
  6. "Upload for X" uploads straight to that variation.
  7. The ⋯ menu's Change base image, Upload new base image and Remove work. Base shows the whole base image without a frame.
  8. After save, while conversions generate, spinners show and the selection does not jump back to the first variation (Review Focus 1).
  9. A failed conversion shows a dot and Retry in its toolbar.
  10. Dark mode, and the field in the visual editor's inspector (narrow): the strip scrolls and nothing overflows.
