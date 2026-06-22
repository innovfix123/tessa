# Employee Photos for ID Cards

This folder holds the photos rendered on `public/team-id-cards.html`.

## Naming convention

Each card looks for `{slug}.jpg` first, then `.jpeg`, `.png`, `.webp` as fallbacks.
If none of those exist the card auto-renders a gradient initials circle, so it's safe
to ship the page before every photo is in.

## How to use

1. Extract `empolyee photos for id card-*.zip` somewhere on your machine.
2. Rename each file to its slug (see list below) — square crop preferred, ~600×600 px or larger.
3. Drop them in this folder (`public/img/employees/`).
4. Hard-refresh `team-id-cards.html` — photos should appear in their badges.

## Expected filenames (41 employees, in card order)

### Founder
- [ ] `jp.jpg` — JP — CEO — HIM-000

### Direct Reports
- [ ] `bala.jpg` — Bala — COO — HIM-001
- [ ] `sneha-sunoj.jpg` — Sneha Sunoj — Ops Manager — HIM-002
- [ ] `nandha.jpg` — Nandha — CMO + BLR PM — HIM-003
- [ ] `ayush.jpg` — Ayush — CFO · Co-founder — HIM-004
- [ ] `yuvanesh.jpg` — Yuvanesh — Tech Lead — HIM-005
- [ ] `fida.jpg` — Fida — Lead AI Engineer — HIM-006
- [ ] `sneha-prathap.jpg` — Sneha Prathap — Gen AI Developer — HIM-007

### Ops (under Sneha Sunoj)
- [ ] `meghana.jpg` — Meghana — Business Analyst — HIM-008
- [ ] `nisha.jpg` — Nisha — Tamil Support — HIM-009
- [ ] `gousia.jpg` — Gousia — Telugu Support — HIM-010
- [ ] `deeksha.jpg` — Deeksha — Kannada Support — HIM-011
- [ ] `reshma.jpg` — Reshma — Malayalam Support — HIM-012

### Product Operations (under Bala)
- [ ] `suwetha-s.jpg` — Suwetha S — Technical Support — HIM-013
- [ ] `tamil-arasan.jpg` — Tamil Arasan — Product Manager — HIM-014
- [ ] `dhanush.jpg` — Dhanush — Product Manager — HIM-015

### Marketing (under Nandha)
- [ ] `anirudh.jpg` — Anirudh — Performance Marketing — HIM-016
- [ ] `anindita.jpg` — Anindita — Growth Manager · North — HIM-017
- [ ] `anjali-bhatt.jpg` — Anjali Bhatt — Bengali Support — HIM-018
- [ ] `krishnan.jpg` — Krishnan — Content Lead — HIM-019
- [ ] `disha.jpg` — Disha — Content Creator — HIM-020
- [ ] `tiyasa.jpg` — Tiyasa — Content Creator · BLR — HIM-021
- [ ] `maansi.jpg` — Maansi — Content Creator · BLR — HIM-022
- [ ] `haripriya.jpg` — Haripriya — Content Creator — HIM-023
- [ ] `kishore-prabakaran.jpg` — Kishore Prabakaran — Content Creator — HIM-024
- [ ] `fathima-k-p.jpg` — Fathima K P — Content Creator — HIM-025
- [ ] `anaz.jpg` — Anaz — Video Editor — HIM-026
- [ ] `sooraj.jpg` — Sooraj — Graphic Designer — HIM-027
- [ ] `swapna-m.jpg` — Swapna M — Performance Marketer — HIM-028

### Finance (under Ayush)
- [ ] `shoyab.jpg` — Shoyab — Accountant — HIM-029
- [ ] `karuna-behal.jpg` — Karuna Behal — Finance Intern — HIM-030
- [ ] `irisha.jpg` — Irisha — Founder's Office — HIM-031

### AI R&D (reports to CEO)
- [ ] `ranjini.jpg` — Ranjini — AI-QA — HIM-032

### Engineering (under Yuvanesh)
- [ ] `rishabh.jpg` — Rishabh — Full Stack Dev — HIM-033
- [ ] `barkha-agarwal.jpg` — Barkha Agarwal — Intern — HIM-034
- [ ] `raksha.jpg` — Raksha — QA Analyst — HIM-035
- [ ] `laxmi.jpg` — Laxmi — QA Intern — HIM-036
- [ ] `perumal.jpg` — Perumal — Full Stack Dev — HIM-037
- [ ] `maari.jpg` — Maari — Full Stack Dev — HIM-038
- [ ] `saran.jpg` — Saran — Data Analyst — HIM-039
- [ ] `iksha-h-s.jpg` — Iksha H S — QA Intern — HIM-040

## Tips

- **Square crop matters** — photos are shown in a 1:1 box. Headshot framed roughly center-of-face works best.
- **At least 400×400 px** — any smaller looks soft on retina screens.
- **JPG is fine** — keeps page light. WebP also supported.
- Any file not in this folder gracefully falls back to a colored initials circle (dept-tinted) — page never breaks.
