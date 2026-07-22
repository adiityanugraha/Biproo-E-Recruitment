<?php
// Widget chatbot status kandidat (Fase 3 Day 3). Hanya sisi kandidat.
// Self-contained: style + markup + script. Riwayat percakapan disimpan di
// memori JS (dikirim tiap request) - tanpa tabel DB (YAGNI).
?>
<style>
  #ereqChatBtn { position: fixed; right: 22px; bottom: 22px; z-index: 90; width: 58px; height: 58px; border-radius: 50%;
    border: none; cursor: pointer; font-size: 25px; line-height: 1; padding: 0; color: #fff;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #FBA919, #F7941D); box-shadow: 0 6px 18px rgba(247,148,29,.45); }
  #ereqChat { position: fixed; right: 22px; bottom: 90px; z-index: 91; width: 340px; max-width: calc(100vw - 44px);
    height: 460px; max-height: calc(100vh - 130px); background: #fff; border-radius: 16px; display: none; flex-direction: column;
    overflow: hidden; box-shadow: 0 12px 40px rgba(0,0,0,.22); }
  #ereqChat.open { display: flex; }
  #ereqChat .hd { background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; padding: 12px 16px; font-weight: 700;
    display: flex; align-items: center; justify-content: space-between; }
  #ereqChat .hd small { font-weight: 500; opacity: .9; font-size: 11px; display: block; }
  #ereqChat .hd button { background: none; border: none; color: #fff; font-size: 22px; line-height: 1; cursor: pointer; margin: 0; padding: 0; }
  #ereqChat .msgs { flex: 1; overflow-y: auto; padding: 14px; background: #F7F8FB; display: flex; flex-direction: column; gap: 10px; }
  #ereqChat .m { max-width: 82%; padding: 9px 12px; border-radius: 12px; font-size: 13px; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; }
  #ereqChat .m.bot { background: #fff; border: 1px solid #eef0f5; align-self: flex-start; border-bottom-left-radius: 4px; }
  #ereqChat .m.me { background: linear-gradient(135deg, #FBD97A, #F5B301); color: #4a3400; align-self: flex-end; border-bottom-right-radius: 4px; }
  #ereqChat .m.err { background: #FDECEC; border: 1px solid #E23B4E; color: #a12734; align-self: flex-start; }
  #ereqChat .ft { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #eef0f5; background: #fff; }
  #ereqChat .ft input { flex: 1; padding: 9px 12px; border: 1px solid #e2e6ee; border-radius: 10px; font-size: 13px; font-family: inherit; }
  #ereqChat .ft button { margin: 0; padding: 9px 16px; border: none; border-radius: 10px; cursor: pointer; font-weight: 700;
    font-size: 13px; background: linear-gradient(90deg, #FBA919, #F7941D); color: #fff; }
  #ereqChat .ft button:disabled { opacity: .55; cursor: default; }
</style>

<button id="ereqChatBtn" onclick="ereqChatToggle()" title="Tanya status lamaran">💬</button>
<div id="ereqChat">
  <div class="hd">
    <div>Asisten Status<small>Tanya sampai mana lamaran Anda</small></div>
    <button onclick="ereqChatToggle()" title="Tutup">&times;</button>
  </div>
  <div class="msgs" id="ereqChatMsgs"></div>
  <div class="ft">
    <input id="ereqChatInput" type="text" placeholder="Ketik pertanyaan..." autocomplete="off"
           onkeydown="if(event.key==='Enter')ereqChatSend()">
    <button id="ereqChatSend" onclick="ereqChatSend()">Kirim</button>
  </div>
</div>

<script>
(function () {
  var url = '<?= site_url('chat/ask') ?>';
  var csrfName = '<?= csrf_token() ?>';
  var csrf = '<?= csrf_hash() ?>';
  var history = [];
  var greeted = false;

  window.ereqChatToggle = function () {
    var box = document.getElementById('ereqChat');
    box.classList.toggle('open');
    if (box.classList.contains('open')) {
      if (!greeted) { addMsg('bot', 'Halo! Tanyakan status lamaran Anda, misalnya "sampai tahap mana lamaran saya?"'); greeted = true; }
      document.getElementById('ereqChatInput').focus();
    }
  };

  function addMsg(kind, text) {
    var d = document.createElement('div');
    d.className = 'm ' + kind;
    d.textContent = text;
    var box = document.getElementById('ereqChatMsgs');
    box.appendChild(d);
    box.scrollTop = box.scrollHeight;
    return d;
  }

  window.ereqChatSend = function () {
    var input = document.getElementById('ereqChatInput');
    var q = input.value.trim();
    if (!q) return;
    input.value = '';
    addMsg('me', q);
    history.push({ role: 'user', text: q });

    var btn = document.getElementById('ereqChatSend');
    btn.disabled = true;
    var loading = addMsg('bot', '...');

    var body = new URLSearchParams();
    body.set(csrfName, csrf);
    body.set('question', q);
    body.set('history', JSON.stringify(history.slice(0, -1))); // riwayat sebelum pertanyaan ini

    fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.j.csrf) csrf = res.j.csrf;
        loading.remove();
        if (res.ok && res.j.answer) {
          addMsg('bot', res.j.answer);
          history.push({ role: 'model', text: res.j.answer });
        } else {
          addMsg('err', res.j.error || 'Terjadi kesalahan. Coba lagi.');
        }
      })
      .catch(function () { loading.remove(); addMsg('err', 'Gagal terhubung. Coba lagi.'); })
      .finally(function () { btn.disabled = false; input.focus(); });
  };
})();
</script>
