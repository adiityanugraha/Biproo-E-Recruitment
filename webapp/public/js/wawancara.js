/**
 * Mesin wawancara suara sisi browser.
 *
 * Tiga pekerjaan, dan hanya satu di antaranya yang mengirim data keluar:
 *
 *   1. BICARA  - speechSynthesis membacakan pertanyaan dengan suara Indonesia.
 *   2. DENGAR  - webkitSpeechRecognition mentranskrip jawaban kandidat.
 *   3. SARING  - Web Audio mengukur energi suara di pita bicara, memutuskan
 *                kapan kandidat benar-benar bicara dan kapan sudah selesai.
 *
 * AUDIONYA TIDAK PERNAH DIKIRIM KE SERVER. Yang naik ke CI4 cuma teks transkrip
 * plus dua angka mutu tangkapan (keyakinan pengenal suara dan tinggi derau
 * ruangan). Server tidak pernah memegang rekaman suara siapa pun.
 *
 * ---------------------------------------------------------------------------
 * SEJAUH MANA "MENYARING SUARA" ITU BENAR, DAN DI MANA BATASNYA
 *
 * Yang sungguh dikerjakan lapisan penyaring di berkas ini:
 *
 *   a. Derau ruangan diukur dulu 1,5 detik saat kandidat diminta diam, lalu
 *      gerbangnya dipasang di atas angka itu. Ruangan berkipas angin dan ruangan
 *      sunyi mendapat ambang yang berbeda - ambang tetap akan salah di salah
 *      satunya.
 *   b. Energi yang diukur cuma pita 300-3400 Hz (pita suara manusia). Dengungan
 *      AC, kipas, dan benturan meja hidup di bawah pita itu dan tidak lagi
 *      terhitung sebagai "ada yang bicara".
 *   c. Potongan transkrip yang datang saat gerbang tertutup DIBUANG. Inilah yang
 *      membuang "halusinasi" pengenal suara dari kebisingan latar.
 *   d. Pengenal suara DIMATIKAN selama AI bicara. Tanpa ini, pertanyaan yang
 *      baru diucapkan masuk sendiri sebagai jawaban kandidat.
 *   e. Potongan berkeyakinan rendah dibuang.
 *
 * Yang TIDAK bisa dikerjakan, dan jangan dijanjikan ke siapa pun:
 * webkitSpeechRecognition membuka mikrofonnya SENDIRI. Kita tidak bisa
 * menyuapinya audio yang sudah kita bersihkan. Jadi penyaringan di sini bekerja
 * pada KEPUTUSAN (potongan mana yang diterima), bukan pada gelombang suara yang
 * masuk ke mesin pengenalnya. Peredam derau sungguhan yang bekerja pada
 * gelombang cuma yang bawaan browser, dinyalakan lewat constraint getUserMedia
 * di bawah. Kalau suatu saat butuh lebih dari ini, jalannya STT sisi server -
 * di situ audionya kita yang pegang.
 * ---------------------------------------------------------------------------
 */
window.RuangWawancara = (function () {
  'use strict';

  // --- Penyetelan penyaring suara -----------------------------------------
  var KALIBRASI_MS   = 1500;   // lama mengukur derau ruangan sebelum bertanya
  var GERBANG_FAKTOR = 2.8;    // gerbang = derau dasar x faktor
  var GERBANG_LANTAI = 0.010;  // batas bawah, untuk ruangan yang terlalu sunyi
  var GERBANG_ATAP   = 0.120;  // batas atas, supaya ruangan ribut tidak menutup total
  var MULAI_MS       = 180;    // di atas gerbang selama ini = mulai bicara
  var DIAM_MS        = 2200;   // diam selama ini setelah bicara = jawaban selesai
  var MIN_BICARA_MS  = 600;    // di bawah ini dianggap batuk/dehem, bukan jawaban
  var MAKS_JAWAB_MS  = 120000; // pagar keras satu jawaban: 2 menit
  var MIN_KEYAKINAN  = 0.30;   // potongan di bawah ini dibuang
  var PITA_BAWAH     = 300;    // Hz - di bawahnya dengungan AC dan benturan meja
  var PITA_ATAS      = 3400;   // Hz - di atasnya desis

  var cfg = {};        // { urlMulai, urlJawab, csrfName, csrf }
  var ui = {};         // elemen DOM
  var pengenal = null; // SpeechRecognition
  var suara = null;    // { ctx, analyser, stream, data }
  var derauDasar = 0;
  var gerbang = GERBANG_LANTAI;

  var sesi = {
    berjalan: false,
    mendengar: false,     // pengenal suara sedang hidup
    bicaraAi: false,      // AI sedang mengucapkan sesuatu
    pertanyaan: '',
    potongan: [],         // transkrip final yang LOLOS saringan
    keyakinan: [],
    mulaiJawabAt: 0,
    totalBicaraMs: 0,
    terakhirSuaraAt: 0,
    sedangBicara: false,
    menunggu: false,      // sedang menunggu jawaban server
  };

  // =========================================================================
  // Dukungan browser
  // =========================================================================

  function Pengenal() {
    return window.SpeechRecognition || window.webkitSpeechRecognition || null;
  }

  function didukung() {
    return !!(Pengenal() && window.speechSynthesis && navigator.mediaDevices);
  }

  // =========================================================================
  // Suara AI (TTS)
  // =========================================================================

  /**
   * Cari suara Bahasa Indonesia. Windows 10/11 membawa "Microsoft Andika" atau
   * "Microsoft Gadis"; Chrome menambah suara Google id-ID saat daring. Kalau
   * satu pun tak ada, biarkan browser memilih sendiri - lebih baik pertanyaan
   * terdengar beraksen daripada tidak terdengar sama sekali.
   */
  function suaraIndonesia() {
    var daftar = window.speechSynthesis.getVoices() || [];
    for (var i = 0; i < daftar.length; i++) {
      if ((daftar[i].lang || '').toLowerCase().indexOf('id') === 0) return daftar[i];
    }
    return null;
  }

  /**
   * Ucapkan teks, lalu jalankan lanjut().
   *
   * Pengenal suara dimatikan selama AI bicara dan baru dihidupkan lagi
   * sesudahnya. Peredam gema browser tidak cukup di sini: yang kita cegah bukan
   * gema, melainkan mesin pengenal yang dengan patuh mentranskrip pertanyaan
   * kita sendiri sebagai jawaban kandidat.
   */
  function ucapkan(teks, lanjut) {
    berhentiMendengar();
    sesi.bicaraAi = true;
    status('bicara', 'AI sedang bertanya - mohon dengarkan');

    var u = new SpeechSynthesisUtterance(teks);
    var v = suaraIndonesia();
    if (v) u.voice = v;
    u.lang = 'id-ID';
    u.rate = 0.95;   // sedikit lebih pelan: pertanyaan wawancara perlu dicerna
    u.pitch = 1.0;

    var sudah = false;
    function selesai() {
      if (sudah) return;
      sudah = true;
      sesi.bicaraAi = false;
      if (lanjut) lanjut();
    }
    u.onend = selesai;
    u.onerror = selesai;

    // Chrome kadang menelan onend bila tab kehilangan fokus. Pagar waktu kasar
    // berdasar panjang teks supaya wawancara tidak tergantung selamanya.
    setTimeout(selesai, 1500 + teks.length * 90);

    window.speechSynthesis.cancel();
    window.speechSynthesis.speak(u);
  }

  // =========================================================================
  // Pengukur suara masuk (Web Audio) - dasar seluruh penyaringan
  // =========================================================================

  function bukaMikrofon() {
    return navigator.mediaDevices.getUserMedia({
      audio: {
        // Tiga peredam bawaan browser. INI satu-satunya lapisan yang bekerja
        // pada gelombang suaranya, bukan pada keputusan kita.
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
        channelCount: 1,
      },
    }).then(function (stream) {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      var ctx = new Ctx();
      var src = ctx.createMediaStreamSource(stream);

      // Rantai pengukur: buang di luar pita suara manusia SEBELUM energinya
      // dihitung. Tanpa ini, dengungan AC 50 Hz sendirian sudah cukup membuka
      // gerbang dan wawancara mengira kandidat sedang bicara terus-menerus.
      var hp = ctx.createBiquadFilter();
      hp.type = 'highpass';
      hp.frequency.value = PITA_BAWAH;
      var lp = ctx.createBiquadFilter();
      lp.type = 'lowpass';
      lp.frequency.value = PITA_ATAS;

      var analyser = ctx.createAnalyser();
      analyser.fftSize = 1024;
      analyser.smoothingTimeConstant = 0.2;

      src.connect(hp);
      hp.connect(lp);
      lp.connect(analyser);
      // analyser TIDAK disambung ke ctx.destination: menyambungkannya berarti
      // suara kandidat keluar lagi lewat speakernya sendiri.

      suara = { ctx: ctx, analyser: analyser, stream: stream, data: new Float32Array(analyser.fftSize) };
      return suara;
    });
  }

  /** Akar rerata kuadrat satu bingkai - ukuran kenyaringan sesaat. */
  function rms() {
    if (!suara) return 0;
    suara.analyser.getFloatTimeDomainData(suara.data);
    var jml = 0;
    for (var i = 0; i < suara.data.length; i++) jml += suara.data[i] * suara.data[i];
    return Math.sqrt(jml / suara.data.length);
  }

  /**
   * Ukur derau ruangan, lalu pasang gerbang di atasnya.
   *
   * Yang diambil persentil 90, bukan rata-rata: derau ruangan itu tidak rata,
   * ada dengung tetap plus sesekali suara dari luar. Rata-rata membuat gerbang
   * terlalu rendah sehingga tiap kendaraan lewat dianggap kandidat bicara.
   */
  function kalibrasi() {
    return new Promise(function (selesai) {
      var contoh = [];
      var habis = Date.now() + KALIBRASI_MS;
      (function ambil() {
        contoh.push(rms());
        if (Date.now() < habis) {
          setTimeout(ambil, 30);
          return;
        }
        contoh.sort(function (a, b) { return a - b; });
        derauDasar = contoh[Math.floor(contoh.length * 0.9)] || 0;
        gerbang = Math.min(GERBANG_ATAP, Math.max(GERBANG_LANTAI, derauDasar * GERBANG_FAKTOR));
        selesai();
      })();
    });
  }

  /**
   * Loop pemantau: menggerakkan meteran, menandai mulai/berhenti bicara, dan
   * menutup jawaban setelah kandidat diam cukup lama.
   *
   * requestAnimationFrame, bukan setInterval: tab yang tidak terlihat
   * membekukannya, dan itu memang yang diinginkan - kandidat yang berpindah tab
   * tidak boleh "dianggap sedang menjawab".
   */
  function pantau() {
    if (!sesi.berjalan) return;
    requestAnimationFrame(pantau);

    var v = rms();
    meter(v);

    if (!sesi.mendengar || sesi.bicaraAi || sesi.menunggu) return;

    var kini = Date.now();
    if (v > gerbang) {
      if (!sesi.sedangBicara) {
        sesi.sedangBicara = true;
        sesi.mulaiBicaraAt = kini;
      }
      // Baru dihitung sebagai bicara setelah bertahan MULAI_MS: satu bingkai
      // keras itu pintu ditutup, bukan orang menjawab.
      if (kini - sesi.mulaiBicaraAt >= MULAI_MS) {
        // Jumlahkan hanya selisih antar bingkai yang berdekatan. Jeda panjang
        // (kandidat berpikir) memang tidak boleh terhitung sebagai lama bicara -
        // angka inilah yang memutuskan "ini jawaban" atau "ini cuma dehem".
        var jeda = kini - sesi.terakhirSuaraAt;
        if (sesi.terakhirSuaraAt > 0 && jeda <= 400) sesi.totalBicaraMs += jeda;
        sesi.terakhirSuaraAt = kini;
        status('dengar', 'Mendengarkan jawaban Anda...');
      }
    } else {
      sesi.sedangBicara = false;
    }

    var pernahBicara = sesi.terakhirSuaraAt > 0;
    var diamCukup = pernahBicara && kini - sesi.terakhirSuaraAt > DIAM_MS;
    var kelamaan = sesi.mulaiJawabAt > 0 && kini - sesi.mulaiJawabAt > MAKS_JAWAB_MS;

    if ((diamCukup && sesi.totalBicaraMs >= MIN_BICARA_MS) || kelamaan) {
      tutupJawaban();
    }
  }

  // =========================================================================
  // Pengenalan suara (STT) + saringan potongan
  // =========================================================================

  function mulaiMendengar() {
    var P = Pengenal();
    if (!P) return;

    pengenal = new P();
    pengenal.lang = 'id-ID';
    pengenal.continuous = true;
    pengenal.interimResults = true;
    pengenal.maxAlternatives = 1;

    pengenal.onresult = function (e) {
      var sementara = '';
      for (var i = e.resultIndex; i < e.results.length; i++) {
        var hasil = e.results[i];
        var teks = (hasil[0].transcript || '').trim();
        if (!teks) continue;

        if (!hasil.isFinal) {
          sementara += teks + ' ';
          continue;
        }

        // SARINGAN. Potongan final hanya diterima bila kandidat memang terukur
        // sedang bicara saat itu, dan pengenalnya cukup yakin.
        var yakin = typeof hasil[0].confidence === 'number' ? hasil[0].confidence : 1;
        var barusanBicara = Date.now() - sesi.terakhirSuaraAt < 1800;

        if (sesi.bicaraAi || !barusanBicara) {
          catatBuangan('di luar giliran bicara', teks);
          continue;
        }
        if (yakin > 0 && yakin < MIN_KEYAKINAN) {
          catatBuangan('keyakinan rendah (' + yakin.toFixed(2) + ')', teks);
          continue;
        }

        sesi.potongan.push(teks);
        if (yakin > 0) sesi.keyakinan.push(yakin);
      }
      tampilTranskrip(sesi.potongan.join(' '), sementara.trim());
    };

    pengenal.onerror = function (e) {
      if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
        gagal('Akses mikrofon ditolak. Izinkan mikrofon di ikon gembok pada bilah alamat, lalu muat ulang halaman.');
      }
      // 'no-speech' dan 'aborted' itu wajar: onend di bawah yang menyalakannya lagi
    };

    pengenal.onend = function () {
      // Chrome mematikan pengenal sendiri setelah beberapa puluh detik sunyi.
      // Selama masih giliran kandidat, hidupkan lagi.
      if (sesi.mendengar && !sesi.bicaraAi && !sesi.menunggu) {
        try { pengenal.start(); } catch (err) { /* sudah jalan */ }
      }
    };

    sesi.mendengar = true;
    try { pengenal.start(); } catch (err) { /* sudah jalan */ }
  }

  function berhentiMendengar() {
    sesi.mendengar = false;
    if (pengenal) {
      try { pengenal.stop(); } catch (err) { /* belum jalan */ }
    }
  }

  // =========================================================================
  // Alur wawancara
  // =========================================================================

  function mulaiGiliran(kartu) {
    if (kartu.selesai) {
      ucapkan(kartu.pertanyaan, function () {
        sesi.berjalan = false;
        status('selesai', 'Wawancara AI selesai');
        ui.pertanyaan.textContent = kartu.pertanyaan;
        ui.kemajuan.textContent = 'Selesai';
        ui.tombol.style.display = 'none';
        ui.penutup.style.display = '';
      });
      return;
    }

    sesi.pertanyaan = kartu.pertanyaan;
    sesi.potongan = [];
    sesi.keyakinan = [];
    sesi.totalBicaraMs = 0;
    sesi.terakhirSuaraAt = 0;
    sesi.mulaiJawabAt = 0;
    sesi.sedangBicara = false;
    sesi.menunggu = false;

    ui.pertanyaan.textContent = kartu.pertanyaan;
    ui.label.textContent = labelSumber(kartu.sumber);
    ui.kemajuan.textContent = 'Pertanyaan ' + kartu.nomor + ' dari ' + kartu.total;
    tampilTranskrip('', '');

    ucapkan(kartu.pertanyaan, function () {
      sesi.mulaiJawabAt = Date.now();
      status('tunggu', 'Silakan menjawab - saya berhenti sendiri saat Anda diam sejenak');
      mulaiMendengar();
    });
  }

  function labelSumber(sumber) {
    if (sumber === 'lanjutan') return 'Pertanyaan pendalaman dari jawaban Anda tadi';
    if (sumber === 'ulangi') return 'Diulang - jawaban tadi kurang tertangkap';
    return 'Pertanyaan wawancara';
  }

  /** Kandidat selesai menjawab: kirim transkrip, minta pertanyaan berikutnya. */
  function tutupJawaban() {
    if (sesi.menunggu) return;
    sesi.menunggu = true;
    berhentiMendengar();

    var teks = sesi.potongan.join(' ').trim();
    status('kirim', 'Menyimpan jawaban Anda...');

    var rerata = sesi.keyakinan.length
      ? sesi.keyakinan.reduce(function (a, b) { return a + b; }, 0) / sesi.keyakinan.length
      : null;

    var body = new URLSearchParams();
    body.set(cfg.csrfName, cfg.csrf);
    body.set('teks', teks);
    body.set('durasi_ms', String(Math.round(sesi.totalBicaraMs)));
    body.set('derau', derauDasar.toFixed(4));
    if (rerata !== null) body.set('keyakinan', rerata.toFixed(3));

    kirim(cfg.urlJawab, body).then(mulaiGiliran).catch(function (e) {
      gagal(e.message);
    });
  }

  function kirim(url, body) {
    return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
      .then(function (r) {
        return r.json().then(function (j) {
          if (j.csrf) cfg.csrf = j.csrf;
          if (!r.ok) throw new Error(j.error || 'Server menolak permintaan.');
          return j;
        });
      });
  }

  // =========================================================================
  // Tampilan
  // =========================================================================

  function status(kelas, teks) {
    ui.status.className = 'wp-status ' + kelas;
    ui.status.textContent = teks;
  }

  function meter(v) {
    var tinggi = Math.min(100, Math.round((v / (gerbang * 3 || 1)) * 100));
    ui.bar.style.width = tinggi + '%';
    ui.bar.className = 'wp-bar' + (v > gerbang ? ' aktif' : '');
  }

  function tampilTranskrip(final, sementara) {
    ui.transkrip.textContent = final || '';
    ui.sementara.textContent = sementara || '';
    ui.jawabanKotak.style.display = final || sementara ? '' : 'none';
  }

  /**
   * Potongan yang dibuang saringan ditampilkan, tidak disembunyikan.
   * Kandidat yang jawabannya tidak masuk berhak tahu penyebabnya - kalau tidak,
   * yang ia lihat cuma sistem yang mengabaikannya.
   */
  function catatBuangan(sebab, teks) {
    if (!ui.buangan) return;
    var li = document.createElement('li');
    li.textContent = '"' + teks.slice(0, 60) + '" - diabaikan: ' + sebab;
    ui.buangan.appendChild(li);
    ui.buanganKotak.style.display = '';
    while (ui.buangan.children.length > 5) ui.buangan.removeChild(ui.buangan.firstChild);
  }

  function gagal(pesan) {
    sesi.berjalan = false;
    berhentiMendengar();
    status('gagal', pesan);
    ui.tombol.style.display = '';
    ui.tombol.textContent = 'Coba Mulai Lagi';
  }

  // =========================================================================
  // Pintu masuk
  // =========================================================================

  function pasang(konfigurasi) {
    cfg = konfigurasi;
    ui = {
      status: document.getElementById('wp-status'),
      pertanyaan: document.getElementById('wp-pertanyaan'),
      label: document.getElementById('wp-label'),
      kemajuan: document.getElementById('wp-kemajuan'),
      bar: document.getElementById('wp-bar'),
      transkrip: document.getElementById('wp-transkrip'),
      sementara: document.getElementById('wp-sementara'),
      jawabanKotak: document.getElementById('wp-jawaban'),
      buangan: document.getElementById('wp-buangan'),
      buanganKotak: document.getElementById('wp-buangan-kotak'),
      tombol: document.getElementById('wp-mulai'),
      kendali: document.getElementById('wp-kendali'),
      selesaiBtn: document.getElementById('wp-selesai'),
      ulangBtn: document.getElementById('wp-ulang'),
      penutup: document.getElementById('wp-penutup'),
    };

    if (!didukung()) {
      status('gagal', 'Browser ini belum mendukung pengenalan suara. Gunakan Google Chrome atau Microsoft Edge.');
      ui.tombol.disabled = true;
      return;
    }

    // getVoices() sering kosong pada panggilan pertama; daftarnya menyusul.
    window.speechSynthesis.onvoiceschanged = function () { /* memicu pemuatan */ };

    ui.tombol.addEventListener('click', mulai);
    ui.selesaiBtn.addEventListener('click', function () {
      if (sesi.mendengar) tutupJawaban();
    });
    ui.ulangBtn.addEventListener('click', function () {
      if (sesi.pertanyaan && !sesi.menunggu) {
        ucapkan(sesi.pertanyaan, function () { mulaiMendengar(); });
      }
    });
  }

  function mulai() {
    ui.tombol.style.display = 'none';
    ui.kendali.style.display = '';
    status('siap', 'Meminta izin mikrofon...');

    bukaMikrofon().then(function () {
      sesi.berjalan = true;
      pantau();
      status('kalibrasi', 'Mengukur derau ruangan - mohon diam sebentar (1,5 detik)');
      return kalibrasi();
    }).then(function () {
      var body = new URLSearchParams();
      body.set(cfg.csrfName, cfg.csrf);
      return kirim(cfg.urlMulai, body);
    }).then(function (kartu) {
      ucapkan(cfg.sapaan, function () { mulaiGiliran(kartu); });
    }).catch(function (e) {
      gagal(e && e.name === 'NotAllowedError'
        ? 'Akses mikrofon ditolak. Izinkan mikrofon lalu coba lagi.'
        : (e.message || 'Gagal memulai wawancara.'));
    });
  }

  return { pasang: pasang };
})();
