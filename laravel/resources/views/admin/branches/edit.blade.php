@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0891b2,#06b6d4);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-pen-to-square me-2"></i>Edit branch</h1>
            <p class="mb-0 small opacity-75"><strong>{{ $item->branch_name }}</strong> · {{ $item->branch_code }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.branches.show', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sitemap me-1"></i> View
            </a>
            <a href="{{ route('admin.branches.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-building me-1 text-info"></i> Branch details</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.branches.update', $item) }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="branch_code">Branch code <span class="text-danger">*</span></label>
                                <input type="text" id="branch_code" name="branch_code" class="form-control @error('branch_code') is-invalid @enderror"
                                       required value="{{ old('branch_code', $item->branch_code) }}">
                                @error('branch_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="branch_name">Branch name <span class="text-danger">*</span></label>
                                <input type="text" id="branch_name" name="branch_name" class="form-control @error('branch_name') is-invalid @enderror"
                                       required value="{{ old('branch_name', $item->branch_name) }}">
                                @error('branch_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="phone">Phone</label>
                                <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror"
                                       value="{{ old('phone', $item->phone) }}">
                                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                       value="{{ old('email', $item->email) }}">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="address">Full address</label>
                                <textarea id="address" name="address" class="form-control @error('address') is-invalid @enderror" rows="3">{{ old('address', $item->address) }}</textarea>
                                @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                           {{ old('is_active', $item->is_active ? 1 : 0) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        {{-- ===== Invoice Header / Footer Image Upload ===== --}}
                        <hr class="my-4">
                        <h3 class="h6 mb-3"><i class="fas fa-file-invoice me-1 text-amber-500"></i> Invoice Print Settings</h3>
                        <p class="small text-muted mb-3">
                            Upload branch-specific header and footer images for printed invoices.
                            These images appear on every page of multi-page invoices.
                        </p>

                        {{-- Add enctype for file upload --}}
                        @php $currentForm = 'update'; @endphp

                        <div class="row g-3">
                            {{-- Header Image --}}
                            <div class="col-md-6">
                                <label class="form-label" for="invoice_header_image">
                                    <i class="fas fa-arrow-up me-1"></i> Invoice Header Image
                                </label>
                                <input type="file" id="invoice_header_image" name="invoice_header_image"
                                       class="form-control @error('invoice_header_image') is-invalid @enderror"
                                       accept="image/png,image/jpeg,image/gif,image/webp">
                                <div class="form-text">
                                    Recommended: <strong>750 × 200 px</strong> (full A4 width, ~5 cm height).
                                    PNG with transparent background works best.
                                </div>
                                @if ($item->invoice_header_image)
                                    <div class="mt-2">
                                        <img src="{{ Storage::disk('public')->url($item->invoice_header_image) }}"
                                             alt="Current header" style="max-width:100%; max-height:100px; border:1px solid #dee2e6; border-radius:4px;">
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox" id="remove_header" name="remove_header" value="1">
                                            <label class="form-check-label small text-danger" for="remove_header">Remove current header</label>
                                        </div>
                                    </div>
                                @endif
                                @error('invoice_header_image') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            {{-- Footer Image --}}
                            <div class="col-md-6">
                                <label class="form-label" for="invoice_footer_image">
                                    <i class="fas fa-arrow-down me-1"></i> Invoice Footer Image
                                </label>
                                <input type="file" id="invoice_footer_image" name="invoice_footer_image"
                                       class="form-control @error('invoice_footer_image') is-invalid @enderror"
                                       accept="image/png,image/jpeg,image/gif,image/webp">
                                <div class="form-text">
                                    Recommended: <strong>750 × 150 px</strong> (full A4 width, ~4 cm height).
                                </div>
                                @if ($item->invoice_footer_image)
                                    <div class="mt-2">
                                        <img src="{{ Storage::disk('public')->url($item->invoice_footer_image) }}"
                                             alt="Current footer" style="max-width:100%; max-height:80px; border:1px solid #dee2e6; border-radius:4px;">
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox" id="remove_footer" name="remove_footer" value="1">
                                            <label class="form-check-label small text-danger" for="remove_footer">Remove current footer</label>
                                        </div>
                                    </div>
                                @endif
                                @error('invoice_footer_image') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            {{-- Header Text --}}
                            <div class="col-md-6">
                                <label class="form-label" for="invoice_header_text">Header Text (below image)</label>
                                <textarea id="invoice_header_text" name="invoice_header_text" class="form-control" rows="2"
                                          placeholder="e.g. Head Office, Branch address…">{{ old('invoice_header_text', $item->invoice_header_text) }}</textarea>
                                <div class="form-text">HTML allowed. Shown below the header image.</div>
                            </div>

                            {{-- Footer Text --}}
                            <div class="col-md-6">
                                <label class="form-label" for="invoice_footer_text">Footer Text (above image)</label>
                                <textarea id="invoice_footer_text" name="invoice_footer_text" class="form-control" rows="2"
                                          placeholder="e.g. Company tagline, additional info…">{{ old('invoice_footer_text', $item->invoice_footer_text) }}</textarea>
                                <div class="form-text">HTML allowed. Shown above the footer image.</div>
                            </div>

                            {{-- Watermark --}}
                            <div class="col-md-6">
                                <label class="form-label" for="invoice_watermark_text">Watermark Text</label>
                                <input type="text" id="invoice_watermark_text" name="invoice_watermark_text" class="form-control"
                                       value="{{ old('invoice_watermark_text', $item->invoice_watermark_text) }}"
                                       placeholder="e.g. STAR REMOTE CENTER">
                                <div class="form-text">Faint diagonal text across the invoice page. Leave blank for no watermark.</div>
                            </div>

                            {{-- Signatory --}}
                            <div class="col-md-3">
                                <label class="form-label" for="invoice_signatory_name">Authorized Signatory Name</label>
                                <input type="text" id="invoice_signatory_name" name="invoice_signatory_name" class="form-control"
                                       value="{{ old('invoice_signatory_name', $item->invoice_signatory_name) }}"
                                       placeholder="e.g. Nurul Absar">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="invoice_signatory_title">Signatory Title</label>
                                <input type="text" id="invoice_signatory_title" name="invoice_signatory_title" class="form-control"
                                       value="{{ old('invoice_signatory_title', $item->invoice_signatory_title) }}"
                                       placeholder="e.g. Manager, Proprietor">
                            </div>

                            {{-- Terms --}}
                            <div class="col-12">
                                <label class="form-label" for="invoice_terms">Invoice Terms & Conditions</label>
                                <textarea id="invoice_terms" name="invoice_terms" class="form-control" rows="3"
                                          placeholder="e.g. ১। আমাদের ক্রয়কৃত মাল নিচে পরিশোধ…">{{ old('invoice_terms', $item->invoice_terms) }}</textarea>
                                <div class="form-text">Bengali or English. Shown at the bottom of the invoice.</div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i> Save changes
                            </button>
                            <a href="{{ route('admin.branches.show', $item) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-circle-info me-1 text-info"></i> Snapshot</h3>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Code</dt>
                        <dd class="col-7">{{ $item->branch_code }}</dd>
                        <dt class="col-5 text-muted">Status</dt>
                        <dd class="col-7">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Warehouses</dt>
                        <dd class="col-7">{{ $item->warehouses ? $item->warehouses->count() : 0 }}</dd>
                        <dt class="col-5 text-muted">Employees</dt>
                        <dd class="col-7">{{ $item->employees ? $item->employees->count() : 0 }}</dd>
                        <dt class="col-5 text-muted">Created</dt>
                        <dd class="col-7">{{ optional($item->created_at)->format('Y-m-d') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
