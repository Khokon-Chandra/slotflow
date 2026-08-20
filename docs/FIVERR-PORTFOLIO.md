# Fiverr Portfolio — SlotFlow

Fiverr প্রোফাইলের **My Portfolio** সেকশনে এই প্রজেক্টটি যোগ করার জন্য সব কনটেন্ট ও স্ক্রিনশট প্ল্যান। কপি-পেস্ট করার মতো করে সাজানো।

---

## ১. Fiverr-এর ফিল্ড ও সীমা (২০২৬)

| ফিল্ড | সীমা / নিয়ম |
|---|---|
| **Project title** | ১৫–৫০ অক্ষর। প্রস্তাবিত ফরম্যাট: `[Client or brand name] – [Project focus]` |
| **Project description** | ১২০–১,৪০০ অক্ষর |
| **Media files** | প্রতি প্রজেক্টে **১–৫টি** ফাইল |
| **ফরম্যাট** | JPEG, JPG, PNG, GIF, MP4, AVI, PDF |
| **সর্বোচ্চ ফাইল সাইজ** | ৫০ MB |
| **প্রস্তাবিত ইমেজ সাইজ** | **১২৮০ × ৭৬৯ px** (৫:৩ অনুপাত) |
| **নিরাপদ মার্জিন** | বাঁ ও ডান প্রান্ত থেকে অন্তত **৭০ px** ফাঁকা রাখুন — মোবাইলে ক্রপ হয় |
| **অন্য ফিল্ড** | Industry (optional), Start date (মাস + বছর), Duration, Cost |
| **প্রদর্শনের ক্রম** | আপনার বাছাই করা শীর্ষ ২টি প্রজেক্ট আগে, তারপর ফাইলসহ সাম্প্রতিক ৩টি রিভিউ |

> ⚠️ **যাচাই করে নিন।** এই সংখ্যাগুলো Fiverr Help Center-এর তথ্য অনুযায়ী (আগস্ট ২০২৬), তবে Fiverr ফর্ম বদলায়। যোগ করার সময় ফর্মের নিজের counter দেখে মিলিয়ে নিন।

---

## ২. আগে একটি সিদ্ধান্ত: এটি ক্লায়েন্ট কাজ নয়

Fiverr-এর পোর্টফোলিও ফর্ম ক্লায়েন্টের নাম ও প্রজেক্ট খরচ চায়। **SlotFlow-এ কোনো ক্লায়েন্ট নেই** — README-তে স্পষ্ট লেখা: *"Not a product… Every business, person, booking and review in the seed data is invented."*

তাই:

- ❌ কোনো কল্পিত ক্লায়েন্টের নাম দেবেন না। ধরা পড়লে অ্যাকাউন্ট ঝুঁকিতে পড়ে, এবং একটি সৎ পোর্টফোলিও প্রজেক্ট এমনিতেই যথেষ্ট শক্তিশালী।
- ✅ প্রথম লাইনেই **"self-initiated"** বা **"personal project"** লিখে দিন। নিচের সব কপিতে সেটি করা আছে।
- ✅ Client name ফিল্ড খালি রাখা গেলে খালি রাখুন। বাধ্যতামূলক হলে `Self-initiated` বা `Personal project` লিখুন।
- ✅ Cost ফিল্ড বাধ্যতামূলক হলে সবচেয়ে ছোট অনুমোদিত মান দিন — বর্ণনায় তো লেখাই আছে এটি self-initiated।

এই সততাটাই আসলে বিক্রি করে: বেশিরভাগ ডেভেলপার পোর্টফোলিওতে স্ক্রিনশট দেয়, প্রমাণ দেয় না। আপনার কাছে ২০৩টি টেস্ট আর একটি কনকারেন্সি টেস্ট আছে যা গার্ড সরালেই ফেল করে।

---

## ৩. Project title — ৩টি অপশন

সবগুলোই ১৫–৫০ অক্ষরের মধ্যে:

| # | টাইটেল | অক্ষর | কখন বাছবেন |
|---|---|---:|---|
| **১** | `SlotFlow – AI Appointment Booking System` | ৪০ | **সুপারিশ।** সবচেয়ে স্পষ্ট, সার্চেবল |
| ২ | `SlotFlow – Laravel Booking System with AI` | ৪১ | Laravel গিগের সাথে মেলাতে চাইলে |
| ৩ | `SlotFlow – Booking API + Vue Admin Panel` | ৪০ | full-stack দিকটা জোর দিতে চাইলে |

---

## ৪. Project description — মূল ভার্সন

**১,৩৪৪ অক্ষর** (সীমা ১,৪০০ — ৫৬ অক্ষর হাতে রাখা)। হুবহু কপি করুন:

```text
SlotFlow is a self-initiated appointment booking system for small service businesses — a salon, a physio practice, a tutor. It answers the three ways they lose money: double bookings, no-shows, and abandoned booking forms.

Double booking is prevented in the database, not in application code. A transaction locks the staff member's row, re-checks for an overlap inside the lock, then inserts — so the check and the write are one atomic step. It is tested by forking four real processes at the same slot; remove the lock and the test fails with four winners.

Availability is computed from rules, not stored as slots, so changing someone's hours never leaves stale rows behind. Seven days and thirty days both cost 13 queries. Per-staff timezones and both daylight-saving transitions are tested.

Four features use a language model, and none can write to the database or produce a number. A customer types "a haircut next Tuesday afternoon" and gets real slots. No-show risk is deterministic PHP with a test per factor — the model only writes the explanation a receptionist reads at 8am. Every response says which driver answered, and a deterministic fallback runs the whole app with no API key.

Laravel 13, PHP 8.4, MySQL 8, Vue 3 + Inertia 2, Tailwind 4. 38 REST endpoints, OpenAPI 3.1, 203 tests, PHPStan level 5. All demo data is invented.
```

### কেন এই গঠন

| প্যারা | কাজ |
|---|---|
| ১ | সমস্যা — বায়ার নিজের ব্যবসা চিনতে পারেন |
| ২ | সবচেয়ে শক্ত প্রমাণ প্রথমে। "গার্ড সরালে টেস্ট ফেল করে" — এই এক লাইন আপনাকে বাকিদের থেকে আলাদা করে |
| ৩ | আর্কিটেকচার সিদ্ধান্ত + একটি পরিমাপযোগ্য সংখ্যা |
| ৪ | AI — এবং AI কী **করতে পারে না**, যা আসলে সিনিয়রিটির সংকেত |
| ৫ | স্ট্যাক ও স্কেল, শেষে সততার লাইন |

---

## ৫. Project description — ছোট ভার্সন

**৬৪৮ অক্ষর।** ফর্ম যদি ছোট চায়, বা অন্য গিগে একই প্রজেক্ট দ্বিতীয়বার দিতে চান:

```text
A self-initiated appointment booking system for small service businesses, built to fix the three things that cost them money: double bookings, no-shows, and abandoned booking forms.

Double booking is prevented with a database row lock, not application code — and tested by forking four real processes at one slot. Availability is computed from rules, with per-staff timezones and daylight saving covered. Four AI features assist without deciding: none can write to the database or produce a number, and a deterministic fallback runs the whole app with no API key.

Laravel 13, PHP 8.4, MySQL 8, Vue 3, Tailwind 4. 203 tests. Demo data is invented.
```

---

## ৬. বাকি ফিল্ডগুলো

| ফিল্ড | যা দিন |
|---|---|
| **Industry** | `Software Development` (নাহলে `Technology` / `Other`) |
| **Start date** | `June 2026` — অথবা যে মাসে সত্যিই শুরু করেছেন |
| **Duration** | `2–3 months` |
| **Cost** | খালি রাখুন। বাধ্যতামূলক হলে সবচেয়ে ছোট অনুমোদিত মান |
| **Client** | খালি, অথবা `Self-initiated` |

### Tags / Skills (প্রতিটি ২০ অক্ষরের মধ্যে)

```text
laravel
php
vue.js
mysql
rest api
api development
ai integration
full stack
tailwind css
saas
```

---

## ৭. পাঁচটি স্ক্রিনশট — শট লিস্ট

Fiverr সর্বোচ্চ ৫টি ফাইল নেয়। **প্রথম ছবিটি কভার** — সেটিই থাম্বনেইল হিসেবে ছোট আকারে দেখা যাবে, তাই সেটিতে বড় ও পরিষ্কার কিছু থাকতে হবে।

| # | ফাইলের নাম | কী ক্যাপচার করবেন | কেন এই ক্রম |
|---|---|---|---|
| **১** | `01-booking-assistant.png` | `/book` — `a haircut next Tuesday afternoon` লিখে সাবমিট করার পরের অবস্থা। parsed intent (সার্ভিস, তারিখের সীমা, বিকেল) **এবং** নিচে আসল স্লটগুলো একসাথে ফ্রেমে | কভার। "AI" মুহূর্তটা এখানেই, এবং থাম্বনেইলেও পড়া যায় |
| **২** | `02-dashboard.png` | `/admin` — উপরে AI briefing, চারটি stat tile (Booked today · Expected today · No-show rate · Lost to no-shows), নিচে আজকের ডায়েরি | প্রোডাক্টটা আসল, এটাই দেখায় |
| **৩** | `03-risk-detail.png` | High risk বুকিংয়ে রিস্ক মোডাল খোলা — স্কোর, ব্যান্ড, এবং **পয়েন্টসহ ফ্যাক্টর ব্রেকডাউন** স্পষ্ট পড়া যায় এমনভাবে | "যে স্কোর নিয়ে তর্ক করা যায়" — এটাই AI ফিচারের গভীরতা |
| **৪** | `04-ai-usage.png` | `/admin/ai` — Calls · Spend · Served from cache · Fell back, বাজেটের বিপরীতে খরচ, এবং শেষ কলগুলোর তালিকা | AI-কে বাজেট ও ডিবাগ করা যায় — বায়ার এটা কোথাও দেখেন না |
| **৫** | `05-concurrency-test.png` | **দুই ভাগে একটি ছবি:** বাঁয়ে `BookingService::create()`-এর `lockForUpdate()` অংশ, ডানে টার্মিনালে ৫টি পাস করা কনকারেন্সি টেস্ট | সবচেয়ে বড় দাবির প্রমাণ। ডেভেলপার-বায়ারের কাছে এটিই সিদ্ধান্তমূলক শট |

**বিকল্প:** ৫ নম্বরের বদলে চাইলে `/admin/team/1/hours` (টাইমজোন নোট + split shift) বা `/book` মোবাইল ভিউ দিতে পারেন — তবে সুপারিশ টেস্ট শটটিই, কারণ বর্ণনার সবচেয়ে সাহসী দাবিটা ওটাই সমর্থন করে।

---

## ৮. স্ক্রিনশট বানানোর রেসিপি

### ধাপ ১ — ডেমো ডেটা তৈরি

```bash
php artisan demo:seed --fresh   # ৫৭০টি বুকিং + রিস্ক অ্যাসেসমেন্ট
php artisan serve
npm run dev
```

AI স্ক্রিনশটে সত্যিকারের মডেল-লেখা টেক্সট চাইলে `.env`-এ `ANTHROPIC_API_KEY` বসান। না দিলে provenance ট্যাগে "fallback" দেখাবে — সেটাও সৎ ছবি, কিন্তু কভার শটে মডেল-লেখা টেক্সট বেশি ভালো দেখায়।

### ধাপ ২ — সঠিক আকারে ক্যাপচার

Fiverr চায় **৫:৩** অনুপাত। ব্রাউজারের সাধারণ স্ক্রিনশট এই অনুপাতে হয় না, তাই ভিউপোর্ট আগেই ঠিক করে নিন:

| | মান |
|---|---|
| ভিউপোর্ট | **১৪৪০ × ৮৬৪ px** — এটি হুবহু ৫:৩ |
| ফাইনাল এক্সপোর্ট | **১২৮০ × ৭৬৯ px** |
| ফরম্যাট | PNG |
| Device pixel ratio | ২ (তারপর ছোট করুন — লেখা ঝকঝকে থাকবে) |

Chrome-এ: DevTools → device toolbar (`Cmd+Shift+M`) → Responsive → `1440 × 864` → DPR `2` → তিন-ডট মেনু → **Capture screenshot**। তারপর ১২৮০ × ৭৬৯-এ রিসাইজ করুন।

### ধাপ ৩ — কম্পোজিশনের নিয়ম

- **বাঁ ও ডানে ৭০ px** ফাঁকা রাখুন। মোবাইলে Fiverr দুই পাশ ক্রপ করে; গুরুত্বপূর্ণ লেখা প্রান্তে থাকলে কাটা পড়বে।
- **একটাই থিম** সব ছবিতে — ডার্ক বা লাইট, মেশাবেন না। ডার্ক থিমে গ্যালারিতে বেশি আলাদা দেখায়; লাইট থিমে লেখা সহজে পড়া যায়। যেটাই নিন, পাঁচটিতেই সেটি রাখুন।
- **ব্রাউজার ক্রোম বাদ দিন** — শুধু পেজের কনটেন্ট। `localhost:8000` লেখা URL বার পোর্টফোলিওকে অসম্পূর্ণ দেখায়।
- **৫ নম্বর ছবিটি** ১২৮০ × ৭৬৯ ক্যানভাসে দুই কলামে বসান: বাঁয়ে কোড, ডানে টার্মিনাল। ফন্ট বড় রাখুন — থাম্বনেইলে না পড়া গেলেও পূর্ণ আকারে পড়তে হবে।
- **কিছু ঝাপসা করার দরকার নেই** — সব ডেটা কল্পিত, এবং বর্ণনায় সেটি লেখা আছে।

### ধাপ ৪ — টেস্ট আউটপুট আনা

```bash
./vendor/bin/pest tests/Concurrency
```

টার্মিনালের ফন্ট বড় করে নিন (১৬–১৮pt), উইন্ডো সংকীর্ণ রাখুন যাতে লাইন না ভাঙে। যে অংশটা ফ্রেমে থাকা দরকার:

```
✓ it rejects a second booking for the same slot
✓ it rejects a booking that merely overlaps an existing one
✓ it allows a booking that starts exactly when the previous one ends
✓ it takes an exclusive row lock on the staff member
✓ it lets exactly one of several simultaneous requests win the slot
```

### ধাপ ৫ — রিপোতেও রাখুন

একই ছবিগুলো `docs/img/`-এ রাখলে প্রজেক্ট README-তেও দেখা যাবে — জায়গা তৈরি করা আছে, শুধু comment marker মুছতে হবে।

তবে **নাম দুই জায়গায় আলাদা।** Fiverr-এ ক্রম ঠিক রাখতে `01-`, `02-` প্রিফিক্স লাগে; README প্রিফিক্স ছাড়া নাম খোঁজে:

| Fiverr-এ আপলোড | `docs/img/`-এ কপি |
|---|---|
| `01-booking-assistant.png` | `booking-assistant.png` |
| `02-dashboard.png` | `dashboard.png` |
| `03-risk-detail.png` | `risk-detail.png` |
| `04-ai-usage.png` | `ai-usage.png` |
| `05-concurrency-test.png` | *(README-তে নেই — চাইলে যোগ করা যায়)* |

পূর্ণ তালিকা ও বাকি দুটি ঐচ্ছিক শট `docs/img/README.md`-এ।

---

## ৯. প্রকাশের আগে চেকলিস্ট

- [ ] টাইটেল ১৫–৫০ অক্ষরের মধ্যে, ফর্মের counter দেখে মিলিয়েছি
- [ ] বর্ণনা ১২০–১,৪০০ অক্ষরের মধ্যে
- [ ] প্রথম লাইনে "self-initiated" আছে; কোনো কল্পিত ক্লায়েন্টের নাম নেই
- [ ] ৫টি ছবি, প্রতিটি ১২৮০ × ৭৬৯ px, PNG
- [ ] পাঁচটিতেই একই থিম
- [ ] দুই পাশে ৭০ px নিরাপদ মার্জিন
- [ ] কভার ছবিটি ছোট আকারে (থাম্বনেইলে) দেখে যাচাই করেছি
- [ ] GitHub রিপো পাবলিক, এবং README-তে স্ক্রিনশট বসানো
- [ ] Tags যোগ করা, প্রতিটি ২০ অক্ষরের মধ্যে
- [ ] প্রকাশের পর মোবাইলে খুলে দেখেছি কিছু ক্রপ হয়েছে কি না

---

## ১০. প্রোফাইল ও গিগে একই কথা

পোর্টফোলিও একা কাজ করে না। এক লাইনে যা সব জায়গায় থাকা উচিত:

```text
Laravel + Vue developer. I build booking and scheduling systems where the
hard parts — concurrency, timezones, AI that assists without deciding — are
tested, not hoped for.
```

গিগের বর্ণনায় এই প্রজেক্টের যে দুটি লাইন সবচেয়ে কাজে দেয়:

- *"Double booking prevented with a database row lock, tested by forking four real processes at one slot."*
- *"AI features that degrade to a deterministic fallback — the app runs with no API key at all."*

---

*রেফারেন্স: [Using your Fiverr Portfolio](https://help.fiverr.com/hc/en-us/articles/4413134063633-Using-your-Fiverr-Portfolio) · [Guidelines for selecting Gig images](https://help.fiverr.com/hc/en-us/articles/15863342952977-Guidelines-for-selecting-Gig-images)*
