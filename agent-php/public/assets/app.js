(function () {
  const sessionId = window.AGENT_SESSION_ID;
  if (!sessionId) return;

  const transcript = document.getElementById('transcript');
  const sendForm = document.getElementById('send-form');
  const sendText = document.getElementById('send-text');
  const finishBtn = document.getElementById('finish-btn');
  const retryBtn = document.getElementById('retry-btn');
  const usage = document.getElementById('usage');
  const todoCard = document.getElementById('todo-card');
  const todoList = document.getElementById('todo-list');
  const approvalCard = document.getElementById('approval-card');
  const approvalTool = document.getElementById('approval-tool');
  const approvalArgs = document.getElementById('approval-args');
  const approveBtn = document.getElementById('approve-btn');
  const denyBtn = document.getElementById('deny-btn');
  const diffBtn = document.getElementById('diff-btn');
  const filesBtn = document.getElementById('files-btn');
  const modal = document.getElementById('modal');
  const modalBody = document.getElementById('modal-body');
  const modalClose = document.getElementById('modal-close');

  let lastId = 0;
  let stopped = false;

  function post(action, extra) {
    return fetch('index.php?page=api&action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id=' + encodeURIComponent(sessionId) + (extra || ''),
    });
  }

  function roleLabel(role, toolName) {
    if (role === 'tool') return 'tool: ' + (toolName || '');
    return role;
  }

  function appendMessage(m) {
    const div = document.createElement('div');
    div.className = 'msg role-' + m.role;
    const who = document.createElement('span');
    who.className = 'who';
    who.textContent = roleLabel(m.role, m.tool_name);
    div.appendChild(who);
    div.appendChild(document.createTextNode(m.content || ''));
    transcript.appendChild(div);
  }

  function renderTodos(todos) {
    if (!todos || !todos.length) {
      todoCard.hidden = true;
      return;
    }
    todoCard.hidden = false;
    todoList.innerHTML = '';
    todos.forEach(function (t) {
      const li = document.createElement('li');
      li.className = 'todo-' + t.status;
      li.textContent = (t.status === 'done' ? '☑ ' : t.status === 'in_progress' ? '▶ ' : '☐ ') + t.text;
      todoList.appendChild(li);
    });
  }

  function renderApproval(approval) {
    if (!approval) {
      approvalCard.hidden = true;
      return;
    }
    approvalCard.hidden = false;
    approvalTool.textContent = approval.tool_name;
    try {
      approvalArgs.textContent = JSON.stringify(JSON.parse(approval.arguments_json), null, 2);
    } catch (e) {
      approvalArgs.textContent = approval.arguments_json;
    }
    approveBtn.onclick = function () {
      post('approve', '&approval_id=' + encodeURIComponent(approval.id));
    };
    denyBtn.onclick = function () {
      post('deny', '&approval_id=' + encodeURIComponent(approval.id));
    };
  }

  function openModal(html) {
    modalBody.innerHTML = html;
    modal.hidden = false;
  }

  modalClose && modalClose.addEventListener('click', function () {
    modal.hidden = true;
  });

  function poll() {
    if (stopped) return;
    fetch('index.php?page=api&action=poll&id=' + encodeURIComponent(sessionId) + '&after=' + lastId)
      .then((r) => r.json())
      .then((data) => {
        if (data.error) return;
        (data.messages || []).forEach((m) => {
          appendMessage(m);
          lastId = m.id;
        });
        if (data.messages && data.messages.length) {
          transcript.scrollTop = transcript.scrollHeight;
        }
        renderTodos(data.todos);
        renderApproval(data.pending_approval);
        if (data.session && usage) {
          usage.textContent = 'tokens: ' + data.session.tokens_prompt + ' in / ' + data.session.tokens_completion + ' out';
        }
        if (data.session && ['done', 'error'].includes(data.session.state)) {
          stopped = true;
          setTimeout(() => window.location.reload(), 500);
          return;
        }
        setTimeout(poll, 2000);
      })
      .catch(() => setTimeout(poll, 4000));
  }

  renderTodos(window.AGENT_TODOS);
  renderApproval(window.AGENT_PENDING_APPROVAL);
  poll();

  if (sendForm) {
    sendForm.addEventListener('submit', function (e) {
      e.preventDefault();
      const text = sendText.value.trim();
      if (!text) return;
      sendText.value = '';
      post('send', '&text=' + encodeURIComponent(text));
    });
  }

  if (finishBtn) {
    finishBtn.addEventListener('click', function () {
      if (!confirm('Ask the agent to wrap up now and open a pull request?')) return;
      post('force_finish');
    });
  }

  if (retryBtn) {
    retryBtn.addEventListener('click', function () {
      post('retry').then(() => window.location.reload());
    });
  }

  document.querySelectorAll('[data-action="archive"], [data-action="unarchive"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      post(btn.getAttribute('data-action')).then(() => window.location.reload());
    });
  });

  document.querySelectorAll('[data-action="delete"]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Delete this session and its workspace? This cannot be undone.')) return;
      post('delete').then(() => { window.location.href = 'index.php'; });
    });
  });

  if (diffBtn) {
    diffBtn.addEventListener('click', function () {
      fetch('index.php?page=api&action=diff&id=' + encodeURIComponent(sessionId))
        .then((r) => r.json())
        .then((data) => {
          const pre = document.createElement('pre');
          pre.className = 'code-block';
          pre.textContent = data.diff || '(no changes yet)';
          openModal('<h2>git diff</h2>');
          modalBody.appendChild(pre);
        });
    });
  }

  function browse(path) {
    fetch('index.php?page=api&action=browse&id=' + encodeURIComponent(sessionId) + '&path=' + encodeURIComponent(path))
      .then((r) => r.json())
      .then((data) => {
        if (data.error) {
          openModal('<h2>Files</h2><p class="error">' + data.error + '</p>');
          return;
        }
        const lines = data.listing.split('\n');
        let html = '<h2>Files: ' + path + '</h2><ul class="file-list">';
        if (path !== '.') {
          html += '<li><a href="#" data-dir="..">.. (up)</a></li>';
        }
        lines.forEach(function (line) {
          const [type, name] = [line.slice(0, 5).trim(), line.slice(5)];
          if (!name) return;
          const child = path === '.' ? name : path + '/' + name;
          if (type === 'dir') {
            html += '<li><a href="#" data-dir="' + child + '">' + name + '/</a></li>';
          } else {
            html += '<li><a href="#" data-file="' + child + '">' + name + '</a></li>';
          }
        });
        html += '</ul>';
        openModal(html);
        modalBody.querySelectorAll('[data-dir]').forEach(function (a) {
          a.addEventListener('click', function (e) {
            e.preventDefault();
            const target = a.getAttribute('data-dir');
            browse(target === '..' ? path.split('/').slice(0, -1).join('/') || '.' : target);
          });
        });
        modalBody.querySelectorAll('[data-file]').forEach(function (a) {
          a.addEventListener('click', function (e) {
            e.preventDefault();
            readFile(a.getAttribute('data-file'));
          });
        });
      });
  }

  function readFile(path) {
    fetch('index.php?page=api&action=readfile&id=' + encodeURIComponent(sessionId) + '&path=' + encodeURIComponent(path))
      .then((r) => r.json())
      .then((data) => {
        const pre = document.createElement('pre');
        pre.className = 'code-block';
        pre.textContent = data.error || data.content || '(empty)';
        openModal('<h2>' + path + '</h2>');
        modalBody.appendChild(pre);
      });
  }

  if (filesBtn) {
    filesBtn.addEventListener('click', function () {
      browse('.');
    });
  }
})();
