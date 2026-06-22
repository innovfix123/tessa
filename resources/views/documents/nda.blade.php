@php
    /** @var array $data — built by NdaController::fieldsFor() */
    $blank = '________________________';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Non-Disclosure Agreement — {{ $data['name'] ?? '' }}</title>
    <style>
        @page { margin: 28px 40px; }
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            color: #1a1a1a;
            font-size: 11px;
            line-height: 1.55;
            margin: 0;
        }
        h1.doc-title {
            color: #2f5496;
            font-size: 17px;
            font-weight: bold;
            margin: 0 0 2px;
        }
        .made-on { font-size: 11px; margin: 0 0 14px; }
        h2.sec {
            color: #2f5496;
            font-size: 12px;
            font-weight: bold;
            margin: 14px 0 4px;
        }
        p { margin: 0 0 8px; text-align: justify; }
        .party { margin: 4px 0 8px; }
        .party .nm { font-weight: bold; }
        ul.compete { margin: 4px 0 8px 0; padding-left: 18px; }
        ul.compete li { margin-bottom: 3px; text-align: justify; }
        .witness-title { color: #2f5496; font-size: 13px; font-weight: bold; margin: 22px 0 10px; }
        .sign-block { margin: 0 0 18px; }
        .sign-block .row { margin: 0 0 6px; }
        .underline { display: inline-block; min-width: 200px; border-bottom: 1px solid #1a1a1a; }
        .muted { color: #555; }
    </style>
</head>
<body>
    <h1 class="doc-title">Non-Disclosure Agreement (Employee NDA)</h1>
    <p class="made-on">This Agreement is made on the {{ $data['day'] }} day of {{ $data['month'] }}, {{ $data['year'] }}</p>

    <h2 class="sec">Between</h2>
    <p>Innovfix Pvt Ltd, a company incorporated under the Companies Act, 2013 and having its registered
        office at Indiqube Ascent, 4th Block Koramangala, Bangalore (hereinafter referred to as the
        "Company" or the "Disclosing Party") of the FIRST PART;</p>
    <div class="party">
        <span class="nm">{{ $data['name'] }}</span>, {{ $data['parent_line'] }}<br>
        Address: {{ $data['address'] }}
    </div>
    <p>(hereinafter referred to as the "Employee" or the "Receiving Party") of the SECOND PART.</p>

    <h2 class="sec">1. Definition of Confidential Information</h2>
    <p>Confidential Information shall include, but not be limited to: source code, object code, algorithms,
        software design, business strategies, financial data, intellectual property, customer data, trade
        secrets, contracts, pricing, and any proprietary information disclosed by the Company (Disclosing
        Party) to the Employee (Receiving Party) during employment.</p>

    <h2 class="sec">2. Employee's Obligations</h2>
    <p>The Receiving Party agrees not to disclose, exploit, or misuse Confidential Information for personal
        gain or third-party benefit. All Confidential Information shall only be used for employment purposes
        with the Company. Upon termination, all Company property must be returned.</p>

    <h2 class="sec">3. Intellectual Property Rights</h2>
    <p>Any work, invention, design, or improvement created by the Receiving Party during employment related
        to the Company's business shall be the exclusive property of the Company (Disclosing Party). The
        Receiving Party waives any ownership rights.</p>

    <h2 class="sec">4. Duration &amp; Non-Compete</h2>
    <p>This Agreement shall remain in force throughout employment and shall continue to bind the Receiving
        Party for Two (2) years after termination of employment.</p>
    <p>the Receiving Party shall not:</p>
    <ul class="compete">
        <li>Join, consult, or work for any competitor of the Company engaged in similar business activities;</li>
        <li>Develop, market, or sell any product or service that directly competes with the Company;</li>
        <li>Use, disclose, sell, or distribute any Confidential Information or data belonging to the Company
            for personal benefit or for the benefit of any third party.</li>
    </ul>

    <h2 class="sec">5. Non-Compete Clause (Prevent taking your project or client)</h2>
    <p>The Receiving Party agrees not to engage, directly or indirectly, in any business or activity that is
        in competition with the Company's products or services, or to create, promote, or sell any product
        that replicates or is derived from the Company's project or codebase, for a period of two (2) years
        from the termination of this Agreement.</p>

    <h2 class="sec">6. Code Ownership &amp; No Reuse Clause</h2>
    <p>All source code, scripts, technical documentation, designs, or derivative work created during the
        course of employment or association with the Company (Disclosing Party) shall be the sole property of
        the Company. The Receiving Party shall not use, reuse, sell, or disclose any such code or materials,
        whether modified or not, for any personal or third-party purpose.</p>

    <h2 class="sec">7. No Third-Party Sharing Clause</h2>
    <p>The Receiving Party shall not, under any circumstances, share any part of the source code, technical
        designs, or project strategy with any third party, company, client, or freelancer, without express
        written permission from the Company (Disclosing Party).</p>

    <h2 class="sec">8. Remedies</h2>
    <p>Any breach will cause irreparable harm to the Company. The Company shall be entitled to seek
        injunctions, damages, and legal remedies.</p>

    <h2 class="sec">9. Governing Law &amp; Jurisdiction</h2>
    <p>This Agreement shall be governed by the laws of India and disputes shall be subject to the courts in
        Bangalore, Karnataka.</p>

    <h2 class="sec">10. Entire Agreement</h2>
    <p>This Agreement constitutes the entire understanding between the Company (Disclosing Party) and the
        Employee (Receiving Party) regarding confidentiality and supersedes any prior agreements.</p>

    <div class="witness-title">IN WITNESS WHEREOF</div>

    <div class="sign-block">
        <div class="row">For Innovfix Pvt Ltd (Disclosing Party / Company):</div>
        <div class="row">Signature: <span class="underline"></span></div>
        <div class="row">Name: Jaya Prasad</div>
        <div class="row">Designation: Director</div>
        <div class="row">Date: {{ $data['day'] }} {{ $data['month'] }} {{ $data['year'] }}</div>
    </div>

    <div class="sign-block">
        <div class="row">For Employee (Receiving Party):</div>
        <div class="row">Signature: <span class="underline"></span></div>
        <div class="row">Name: {{ $data['name'] }}</div>
        <div class="row">Date: <span class="muted">_____ / _____ / __________</span></div>
    </div>
</body>
</html>
