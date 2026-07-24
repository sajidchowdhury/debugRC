{{--
  erp/ui-preview.blade.php — storybook-style showcase of all 21 <x-erp.*> components.

  Route: GET /ui-preview (auth-protected). Extends layouts/erp-preview (no sidebar).
  Sample data comes from UiPreviewController::index().
--}}
<x-layouts.erp-preview>
    <div class="min-h-screen">
        {{-- ===== Preview header ===== --}}
        <div class="sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-amber-200 shadow-sm no-print">
            <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 text-sm text-amber-700 hover:text-amber-900 font-medium">
                        <x-erp.icon name="arrow-left" class="size-4" /> Back to Dashboard
                    </a>
                    <span class="text-amber-300">|</span>
                    <div class="bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg px-3 py-1 text-white font-bold text-sm">RC ERP</div>
                    <span class="text-xs text-amber-700 font-medium">UI Component Preview / কম্পোনেন্ট প্রিভিউ</span>
                </div>
                <span class="text-xs text-gray-500">21 components</span>
            </div>
        </div>

        <div class="max-w-6xl mx-auto px-4 py-6 space-y-8">

            {{-- ===== Hero + Journey Stepper ===== --}}
            <x-erp.left-accent-card accent="amber" icon="layout-grid" title="Hero & Journey Stepper" title-bn="হিরো ও জার্নি স্টেপার">
                <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
                    <h1 class="text-2xl font-bold text-white">গোডাউন ও চালান</h1>
                    <p class="text-amber-100 text-lg mt-1">Godown & Challan Management</p>
                    <div class="mt-6">
                        <x-erp.journey-stepper />
                    </div>
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Stat Cards ===== --}}
            <x-erp.left-accent-card accent="amber" icon="banknote" title="Stat Cards" title-bn="পরিসংখ্যান কার্ড">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach ($stats as $stat)
                        <x-erp.stat-card
                            :label="$stat['label']"
                            :label-bn="$stat['label_bn']"
                            :value="$stat['value']"
                            :accent="$stat['accent']"
                            :icon="$stat['icon']" />
                    @endforeach
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Status Pills ===== --}}
            <x-erp.left-accent-card accent="orange" icon="clipboard-list" title="Status Pills" title-bn="স্ট্যাটাস পিল">
                <div class="flex flex-wrap gap-3">
                    @foreach ($statuses as $status)
                        <div class="flex flex-col items-start gap-1">
                            <x-erp.status-pill :status="$status" />
                            <span class="text-[10px] text-gray-400">{{ $status }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-3 pt-3 mt-3 border-t border-gray-100">
                    <x-erp.status-pill status="blank_godown_created" bilingual />
                    <x-erp.status-pill status="godown_prepared" bilingual />
                    <x-erp.status-pill status="challan_issued" bilingual />
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Branch Pills ===== --}}
            <x-erp.left-accent-card accent="red" icon="map-pin" title="Branch Pills" title-bn="শাখা পিল">
                <div class="flex flex-wrap gap-3">
                    @foreach ($branches as $branchCode)
                        <x-erp.branch-pill :branch-code="$branchCode" />
                    @endforeach
                    <x-erp.branch-pill branch-code="HO" bn />
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Step Indicator ===== --}}
            <x-erp.left-accent-card accent="cyan" icon="file-text" title="Step Indicator (4-step workflow)" title-bn="ধাপ নির্দেশক">
                <x-erp.step-indicator :steps="$workflowSteps" />
            </x-erp.left-accent-card>

            {{-- ===== Left Accent Cards ===== --}}
            <x-erp.left-accent-card accent="green" icon="package" title="Left Accent Cards" title-bn="অ্যাকসেন্ট কার্ড">
                <div class="grid md:grid-cols-2 gap-4">
                    <x-erp.left-accent-card accent="red" icon="file-text" title="Invoice Info" title-bn="চালান তথ্য">
                        <p class="text-sm text-gray-600">INV-HO-00009 — Rahim Store</p>
                        <p class="text-xs text-gray-500 mt-1">Total: ৳1,281</p>
                    </x-erp.left-accent-card>
                    <x-erp.left-accent-card accent="orange" icon="package" title="Product Demand" title-bn="পণ্য চাহিদা" :strong="true">
                        <p class="text-sm text-gray-600">3 products, 15 qty, ≈3 CTN</p>
                    </x-erp.left-accent-card>
                    <x-erp.left-accent-card accent="cyan" title="Transport" title-bn="পরিবহন">
                        <p class="text-sm text-gray-600">Original ৳132 → New ৳150</p>
                    </x-erp.left-accent-card>
                    <x-erp.left-accent-card accent="green" icon="check-circle" title="COGS Preview" title-bn="ক্রয়মূল্য প্রাক্কলন">
                        <p class="text-sm text-gray-600">Total COGS: ৳970</p>
                    </x-erp.left-accent-card>
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Buttons ===== --}}
            <x-erp.left-accent-card accent="amber" icon="save" title="Buttons" title-bn="বোতাম">
                <div class="flex flex-wrap items-center gap-3">
                    <x-erp.primary-button accent="amber" icon="save">Save / সংরক্ষণ</x-erp.primary-button>
                    <x-erp.primary-button accent="orange" icon="warehouse">Enter Warehouse Info</x-erp.primary-button>
                    <x-erp.gradient-button icon="truck">Issue Challan / চালান ইস্যু</x-erp.gradient-button>
                    <x-erp.outline-button icon="arrow-left">Cancel / বাতিল</x-erp.outline-button>
                    <x-erp.primary-button accent="amber" disabled>Disabled</x-erp.primary-button>
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Form Controls ===== --}}
            <x-erp.left-accent-card accent="yellow" icon="pencil" title="Form Controls" title-bn="ফর্ম কন্ট্রোল">
                <div class="grid md:grid-cols-2 gap-4">
                    <x-erp.form-input name="transport_name" label="Transport Name" label-bn="পরিবহন নাম" placeholder="Hanif Paribahan" />
                    <x-erp.form-input name="vehicle_number" label="Vehicle Number" label-bn="গাড়ি নং" placeholder="Dhaka-Metro-GA-12-3456" required />
                    <x-erp.form-select name="warehouse_id" label="Warehouse" label-bn="গুদাম" placeholder="Select warehouse"
                        :options="[['value' => 'wh1', 'label' => 'WH-HO-01 Dhaka Main'], ['value' => 'wh2', 'label' => 'WH-HO-02 Mirpur']]" />
                    <x-erp.form-input name="transport_cost" type="number" label="Transport Cost" label-bn="পরিবহন খরচ" :value="150" />
                    <x-erp.form-textarea name="dispatcher_notes" label="Dispatcher Notes" label-bn="ডিসপ্যাচার নোট" :rows="3" placeholder="অতিরিক্ত নির্দেশনা..." field-class="md:col-span-2" />
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Checkbox Cards ===== --}}
            <x-erp.left-accent-card accent="orange" icon="users" title="Checkbox Cards (Dispatcher Picker)" title-bn="চেকবক্স কার্ড">
                <div class="grid md:grid-cols-3 gap-3">
                    @foreach ($dispatchers as $i => $dispatcher)
                        <x-erp.checkbox-card
                            name="dispatchers[]"
                            :value="$dispatcher['id']"
                            :label="$dispatcher['name']"
                            :sublabel="$dispatcher['sub']"
                            accent="red"
                            :checked="$i === 0" />
                    @endforeach
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Filter Chips ===== --}}
            <x-erp.left-accent-card accent="orange" icon="search" title="Filter Chips" title-bn="ফিল্টার চিপ">
                <x-erp.filter-chips :chips="$filterChips" active="all" accent="orange" />
            </x-erp.left-accent-card>

            {{-- ===== Data Table ===== --}}
            <x-erp.left-accent-card accent="amber" icon="package" title="Data Table" title-bn="ডেটা টেবিল">
                <x-erp.data-table :cols="$invoiceCols" :rows="$invoiceRows" row-key="code" />
            </x-erp.left-accent-card>

            {{-- ===== Warning Callout ===== --}}
            <x-erp.left-accent-card accent="red" icon="alert-triangle" title="Warning Callout" title-bn="সতর্কতা">
                <x-erp.warning-callout title="This action is irreversible" title-bn="এই কাজটি ফিরিয়ে আনা যাবে না">
                    <p>Issuing the challan will:</p>
                    <ul class="list-disc list-inside">
                        <li>Deduct stock from the assigned warehouses</li>
                        <li>Post a COGS journal entry</li>
                        <li>Optionally post a transport-adjustment journal entry</li>
                    </ul>
                </x-erp.warning-callout>
            </x-erp.left-accent-card>

            {{-- ===== Empty State + Skeleton ===== --}}
            <x-erp.left-accent-card accent="gray" icon="inbox" title="Empty State & Skeleton" title-bn="খালি অবস্থা ও স্কেলিটন">
                <div class="grid md:grid-cols-2 gap-4">
                    <x-erp.empty-state icon="inbox" title="No invoices found" title-bn="কোনো চালান পাওয়া যায়নি" message="Try changing the filter or branch." message-bn="ফিল্টার বা শাখা পরিবর্তন করে দেখুন।" />
                    <div class="space-y-2">
                        <x-erp.skeleton type="text" class="w-40" />
                        <x-erp.skeleton type="text" class="w-64" />
                        <x-erp.skeleton type="row" />
                        <x-erp.skeleton type="row" />
                        <x-erp.skeleton type="circle" />
                    </div>
                </div>
            </x-erp.left-accent-card>

            {{-- ===== Sticky Action Bar ===== --}}
            <x-erp.left-accent-card accent="amber" icon="save" title="Sticky Action Bar" title-bn="স্টিকি অ্যাকশন বার">
                <p class="text-xs text-gray-500 mb-3">The bar below sticks to the bottom of its scroll container.</p>
                <x-erp.sticky-action-bar>
                    <x-erp.outline-button>Cancel</x-erp.outline-button>
                    <x-erp.gradient-button icon="truck">Issue Challan</x-erp.gradient-button>
                </x-erp.sticky-action-bar>
            </x-erp.left-accent-card>

            {{-- ===== Signature Row (print) ===== --}}
            <x-erp.left-accent-card accent="gray" icon="pencil" title="Signature Row (print)" title-bn="স্বাক্ষর সারি">
                <div class="bg-white p-4 rounded-lg border">
                    <x-erp.signature-row :signers="$signers" />
                </div>
            </x-erp.left-accent-card>

            <div class="h-8"></div>
        </div>
    </div>
</x-layouts.erp-preview>
