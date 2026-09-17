/* AI 产品编辑器：文本预览→应用（同步）；图片先覆盖预览→确认重绘（异步任务+轮询）。无依赖。 */
(function () {
  'use strict';

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', AIPE.nonce);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(AIPE.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (txt) {
          // 剥掉 BOM，避免 PHP 输出里的 \uFEFF 让 JSON.parse 失败
          txt = txt.replace(/^\uFEFF+/, '').trim();
          try {
            return JSON.parse(txt);
          } catch (e) {
            // 非 JSON（多为 PHP 警告/Fatal 或被拦截），把原文带出来便于定位
            throw new Error('服务端返回了非 JSON 内容：' + txt.slice(0, 200));
          }
        });
      });
  }

  function setStatus(el, msg, bad) {
    if (!el) return;
    el.textContent = msg;
    el.classList.toggle('aipe-bad', !!bad);
  }

  var box = document.querySelector('.aipe-box');
  if (!box) return;
  var pid = box.getAttribute('data-product');

  // ---------- 1) 文本：预览 / 应用 ----------
  var previewBtn = document.getElementById('aipe-preview-btn');
  var applyBtn = document.getElementById('aipe-apply-btn');
  var textStatus = document.getElementById('aipe-text-status');
  var textTable = document.getElementById('aipe-text-table');
  var pending = null; // {title_en}

  function row(field, src, dst, kind, key) {
    var tr = document.createElement('tr');
    var esc = function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); };
    tr.innerHTML = '<td>' + esc(field) + '</td><td>' + esc(src) + '</td>' +
      '<td><input type="text" class="large-text" data-kind="' + kind + '" data-key="' + esc(key) + '" value="' + esc(dst).replace(/"/g, '&quot;') + '"></td>';
    return tr;
  }

  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      setStatus(textStatus, '翻译中…');
      previewBtn.disabled = true;
      post('aipe_preview_text', { product_id: pid }).then(function (j) {
        previewBtn.disabled = false;
        if (!j.success) {
          setStatus(textStatus, '失败：' + ((j.data && j.data.code) || '未知'), true);
          return;
        }
        var d = j.data;
        var tb = textTable.querySelector('tbody');
        tb.innerHTML = '';
        tb.appendChild(row('标题', '(见原文)', d.title_en, 'title', '__title__'));
        Object.keys(d.name_map || {}).forEach(function (k) {
          tb.appendChild(row('属性名', k, d.name_map[k], 'name', k));
        });
        Object.keys(d.value_map || {}).forEach(function (k) {
          tb.appendChild(row('属性值', k, d.value_map[k], 'value', k));
        });
        pending = { title_en: d.title_en };
        textTable.hidden = false;
        applyBtn.disabled = false;
        setStatus(textStatus, '预览完成（' + (d.cached ? '缓存' : d.provider || '模型') + '），检查译文后点“应用到商品”。');
      }).catch(function (e) {
        previewBtn.disabled = false;
        setStatus(textStatus, '请求失败：' + e.message, true);
      });
    });
  }

  if (applyBtn) {
    applyBtn.addEventListener('click', function () {
      if (!pending) return;
      var names = {}, values = {}, title = pending.title_en;
      textTable.querySelectorAll('input[data-kind]').forEach(function (inp) {
        if (inp.getAttribute('data-kind') === 'title') { title = inp.value; }
        else if (inp.getAttribute('data-kind') === 'name') { names[inp.getAttribute('data-key')] = inp.value; }
        else { values[inp.getAttribute('data-key')] = inp.value; }
      });
      if (!confirm('确认把译文写入商品吗？全局属性标签改名会影响全站。')) return;
      setStatus(textStatus, '写入中…');
      applyBtn.disabled = true;
      post('aipe_apply_text', {
        product_id: pid, title_en: title,
        name_map: JSON.stringify(names), value_map: JSON.stringify(values)
      }).then(function (j) {
        if (!j.success) {
          setStatus(textStatus, '失败：' + ((j.data && j.data.code) || '未知'), true);
          applyBtn.disabled = false;
          return;
        }
        setStatus(textStatus, '已应用：改名 term ' + j.data.renamed_terms + ' 个，变体 ' + j.data.updated_variations + ' 个。请刷新页面核对。');
      }).catch(function (e) {
        applyBtn.disabled = false;
        setStatus(textStatus, '请求失败：' + e.message, true);
      });
    });
  }

  // ---------- 任务轮询 ----------
  function poll(jobId, statusEl) {
    var timer = setInterval(function () {
      post('aipe_job_status', { job_id: jobId }).then(function (j) {
        if (!j.success) {
          clearInterval(timer);
          setStatus(statusEl, '查询失败', true);
          return;
        }
        var st = j.data.status;
        if (st === 'done') {
          clearInterval(timer);
          var url = j.data.result && j.data.result.url;
          setStatus(statusEl, '完成' + (j.data.result && j.data.result.applied ? '（已挂载）' : '（未挂载，可点链接查看）'));
          if (url) {
            var a = document.createElement('a');
            a.href = url; a.target = '_blank'; a.textContent = '查看新图';
            statusEl.appendChild(document.createTextNode(' '));
            statusEl.appendChild(a);
          }
        } else if (st === 'failed') {
          clearInterval(timer);
          setStatus(statusEl, '失败：' + (j.data.error_code || '未知'), true);
        } else {
          setStatus(statusEl, '任务 #' + jobId + ' ' + st + '…');
        }
      });
    }, 3000);
    setStatus(statusEl, '任务 #' + jobId + ' 已排队…');
  }

  function createJob(payload, statusEl, btns) {
    (btns || []).forEach(function (b) { b.disabled = true; });
    post('aipe_create_job', payload).then(function (j) {
      (btns || []).forEach(function (b) { b.disabled = false; });
      if (!j.success) {
        setStatus(statusEl, '创建失败：' + ((j.data && j.data.code) || '未知'), true);
        return;
      }
      poll(j.data.job_id, statusEl);
    }).catch(function (e) {
      (btns || []).forEach(function (b) { b.disabled = false; });
      setStatus(statusEl, '请求失败：' + e.message, true);
    });
  }

  // ---------- 2) 图片：覆盖预览 → 确认重绘 ----------
  document.querySelectorAll('.aipe-img').forEach(function (card) {
    var att = card.getAttribute('data-att');
    var slot = card.getAttribute('data-slot');
    var statusEl = card.querySelector('.aipe-job-status');
    var overlay = card.querySelector('.aipe-overlay');
    var previewBtn2 = card.querySelector('.aipe-act-preview');
    var confirmBtn = card.querySelector('.aipe-act-confirm');
    var editBtn = card.querySelector('.aipe-act-trans');
    var items = null; // 预览结果 [{box(0-1000), src, dst}]

    function drawOverlay(list) {
      overlay.innerHTML = '';
      list.forEach(function (it) {
        var b = document.createElement('div');
        b.className = 'aipe-bubble';
        // 0-1000 坐标 → 百分比（对照 MoeTranslate 悬浮窗）
        b.style.left = (it.box[0] / 10) + '%';
        b.style.top = (it.box[1] / 10) + '%';
        b.style.width = (it.box[2] / 10) + '%';
        b.style.height = (it.box[3] / 10) + '%';
        b.title = it.src;
        b.textContent = it.dst;
        overlay.appendChild(b);
      });
      overlay.hidden = false;
    }

    previewBtn2.addEventListener('click', function () {
      setStatus(statusEl, 'OCR+翻译中…');
      previewBtn2.disabled = true;
      post('aipe_preview_image', { product_id: pid, attachment_id: att }).then(function (j) {
        previewBtn2.disabled = false;
        if (!j.success) {
          setStatus(statusEl, '失败：' + ((j.data && j.data.code) || '未知'), true);
          return;
        }
        items = j.data.items;
        drawOverlay(items);
        confirmBtn.disabled = false;
        setStatus(statusEl, '预览到 ' + items.length + ' 处文字（悬停看原文），确认后重绘入库。');
      }).catch(function (e) {
        previewBtn2.disabled = false;
        setStatus(statusEl, '请求失败：' + e.message, true);
      });
    });

    confirmBtn.addEventListener('click', function () {
      if (!items) return;
      // 允许运营先改气泡文字再确认：读回 overlay 上的现文
      var bubbles = overlay.querySelectorAll('.aipe-bubble');
      var finalItems = items.map(function (it, i) {
        var t = bubbles[i] ? bubbles[i].textContent : it.dst;
        return { box: it.box, dst: t };
      });
      var auto = card.querySelector('.aipe-auto-apply').checked;
      confirmBtn.disabled = true;
      createJob({
        product_id: pid, kind: 'image_translate',
        attachment_id: att, slot: slot === 'featured' ? 'gallery' : slot,
        auto_apply: auto ? '1' : '', items: JSON.stringify(finalItems)
      }, statusEl, [confirmBtn]);
    });

    editBtn.addEventListener('click', function () {
      var auto = card.querySelector('.aipe-auto-apply').checked;
      createJob({
        product_id: pid, kind: 'image_edit',
        attachment_id: att, slot: slot === 'featured' ? 'gallery' : slot,
        auto_apply: auto ? '1' : ''
      }, statusEl, [editBtn]);
    });
  });

  // ---------- 3) 生成 ----------
  var genBtn = document.getElementById('aipe-gen-btn');
  if (genBtn) {
    genBtn.addEventListener('click', function () {
      var statusEl = document.getElementById('aipe-gen-status');
      createJob({
        product_id: pid, kind: 'image_generate',
        template: document.getElementById('aipe-gen-template').value,
        slot: document.getElementById('aipe-gen-slot').value,
        auto_apply: document.getElementById('aipe-gen-apply').checked ? '1' : '',
        custom_prompt: document.getElementById('aipe-gen-prompt').value
      }, statusEl, [genBtn]);
    });
  }
})();
/* 设置页：百度连接测试（独立作用域，设置页无 .aipe-box 照样跑）。 */
(function () {
  'use strict';
  var btn = document.getElementById('aipe-baidu-test');
  if (!btn || typeof AIPE === 'undefined') return;
  btn.addEventListener('click', function () {
    var st = document.getElementById('aipe-baidu-test-status');
    st.classList.remove('aipe-bad');
    st.textContent = '测试中…（请先保存设置再测）';
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'aipe_test_baidu');
    fd.append('nonce', AIPE.nonce);
    fetch(AIPE.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        btn.disabled = false;
        if (j.success) {
          st.textContent = '连接成功（百度返回码 ' + j.data.code + '，鉴权通过）';
        } else {
          st.textContent = '失败：' + (j.data.detail || j.data.code);
          st.classList.add('aipe-bad');
        }
      })
      .catch(function (e) {
        btn.disabled = false;
        st.textContent = '请求失败：' + e.message;
        st.classList.add('aipe-bad');
      });
  });
})();
