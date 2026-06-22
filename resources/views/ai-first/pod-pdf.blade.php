<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>AI First — Squad Assignments</title>
<style>
  @page { margin: 36pt 36pt 28pt 36pt; }
  * { box-sizing: border-box; }
  body {
    font-family: 'Helvetica', 'Arial', sans-serif;
    color: #111;
    font-size: 11pt;
    line-height: 1.45;
    margin: 0;
  }
  .cover {
    text-align: center;
    padding: 36pt 0 12pt 0;
    border-bottom: 2pt solid #111;
    margin-bottom: 18pt;
  }
  .cover .brand {
    font-size: 9pt;
    letter-spacing: 4pt;
    color: #777;
    text-transform: uppercase;
  }
  .cover h1 {
    margin: 6pt 0 2pt 0;
    font-size: 28pt;
    letter-spacing: -0.5pt;
  }
  .cover .sub {
    font-size: 11pt;
    color: #444;
    margin-top: 2pt;
  }
  .stats {
    margin: 14pt auto 0 auto;
    width: 100%;
    border-collapse: collapse;
    font-size: 10pt;
  }
  .stats td {
    padding: 6pt 8pt;
    text-align: center;
    border: 1pt solid #ddd;
    background: #fafafa;
  }
  .stats td b { font-size: 14pt; display: block; }

  .pod {
    page-break-inside: avoid;
    border: 1pt solid #d6d6d6;
    border-radius: 4pt;
    padding: 12pt 14pt 10pt 14pt;
    margin: 0 0 14pt 0;
  }
  .pod-head {
    border-bottom: 1pt solid #eaeaea;
    padding-bottom: 6pt;
    margin-bottom: 8pt;
  }
  .pod-num {
    font-size: 9pt;
    color: #888;
    letter-spacing: 2pt;
    text-transform: uppercase;
  }
  .pod-mentor {
    font-size: 18pt;
    font-weight: 700;
    margin-top: 2pt;
  }
  .pod-mentor .label {
    font-size: 9pt;
    font-weight: 400;
    color: #888;
    letter-spacing: 1pt;
    text-transform: uppercase;
    margin-right: 8pt;
    vertical-align: middle;
  }
  .pod-assoc {
    font-size: 12pt;
    color: #333;
    margin-top: 2pt;
  }
  .pod-assoc .label {
    font-size: 8.5pt;
    font-weight: 400;
    color: #888;
    letter-spacing: 1pt;
    text-transform: uppercase;
    margin-right: 6pt;
  }
  .pod-mentees-label {
    font-size: 8.5pt;
    color: #888;
    letter-spacing: 1pt;
    text-transform: uppercase;
    margin: 6pt 0 4pt 0;
  }
  .mentees {
    width: 100%;
    border-collapse: collapse;
  }
  .mentees td {
    padding: 4pt 6pt;
    font-size: 11pt;
    width: 50%;
    vertical-align: top;
  }
  .mentees td .num {
    color: #888;
    font-size: 9pt;
    margin-right: 6pt;
  }
  .pod-foot {
    font-size: 8.5pt;
    color: #888;
    text-align: right;
    margin-top: 6pt;
  }
  .footer {
    margin-top: 18pt;
    text-align: center;
    color: #888;
    font-size: 9pt;
    border-top: 1pt solid #ddd;
    padding-top: 8pt;
  }
</style>
</head>
<body>

<div class="cover">
  <div class="brand">InnovFix</div>
  <h1>AI First — Squad Assignments</h1>
  <table class="stats">
    <tr>
      <td><b>4</b>Squads</td>
      <td><b>4</b>Mentors</td>
      <td><b>5</b>Associate Mentors</td>
      <td><b>37</b>Team Members</td>
      <td><b>47</b>Total in AI First</td>
    </tr>
  </table>
</div>

@php
$pods = [
  [
    'num'     => 1,
    'mentor'  => 'Fida',
    'assocs'  => ['Bhoomika'],
    'mentees' => ['Ayush', 'Shoyab', 'Irisha', 'Bhuvan Prasad', 'Akshara', 'Soundaraya'],
  ],
  [
    'num'     => 2,
    'mentor'  => 'Sneha Prathap',
    'assocs'  => ['Ranjini'],
    'mentees' => ['Rishabh', 'Barkha Agarwal', 'Perumal', 'Laxmi', 'Iksha H S', 'Maari', 'Prajwal', 'Tamil Arasan', 'Sumit'],
  ],
  [
    'num'     => 3,
    'mentor'  => 'Yuvanesh',
    'assocs'  => ['Saran', 'Bala'],
    'mentees' => ['Nandha', 'Anirudh', 'Swapna M', 'Anindita', 'Gargi Bisht', 'Sneha Sunoj', 'Deeksha', 'Nisha', 'Gousia', 'Reshma', 'Anjali Bhatt', 'Meghana', 'Dhanush', 'Suwetha S'],
  ],
  [
    'num'     => 4,
    'mentor'  => 'Krishnan',
    'assocs'  => ['Kishore Prabakaran'],
    'mentees' => ['Nehal Y', 'Fathima K P', 'Tiyasa', 'Haripriya', 'Disha', 'Sivaranjani N', 'Sooraj', 'Anaz'],
  ],
];
@endphp

@foreach ($pods as $pod)
<div class="pod">
  <div class="pod-head">
    <div class="pod-num">Squad {{ $pod['num'] }}</div>
    <div class="pod-mentor"><span class="label">Mentor</span>{{ $pod['mentor'] }}</div>
    <div class="pod-assoc"><span class="label">{{ count($pod['assocs']) > 1 ? 'Associate Mentors' : 'Associate Mentor' }}</span>{{ implode(' + ', $pod['assocs']) }}</div>
  </div>
  <div class="pod-mentees-label">Team Members ({{ count($pod['mentees']) }})</div>
  <table class="mentees">
    @php $rows = (int) ceil(count($pod['mentees']) / 2); @endphp
    @for ($i = 0; $i < $rows; $i++)
      <tr>
        <td><span class="num">{{ $i + 1 }}.</span>{{ $pod['mentees'][$i] ?? '' }}</td>
        <td>
          @if (isset($pod['mentees'][$i + $rows]))
            <span class="num">{{ $i + 1 + $rows }}.</span>{{ $pod['mentees'][$i + $rows] }}
          @endif
        </td>
      </tr>
    @endfor
  </table>
  <div class="pod-foot">{{ count($pod['mentees']) + 1 + count($pod['assocs']) }} people total</div>
</div>
@endforeach

<div class="footer">
  Learn. Adapt. Implement. — InnovFix AI First
</div>

</body>
</html>
