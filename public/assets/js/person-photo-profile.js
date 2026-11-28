(() => {
    'use strict';
    const host = document.querySelector('#profile-photo');
    if (!host) return;
    const labels = JSON.parse(document.querySelector('#profile-photo-config').textContent);
    const node = (tag, attributes = {}, ...children) => {
        const element = document.createElement(tag);
        for (const [key, value] of Object.entries(attributes)) {
            if (key.startsWith('on')) element.addEventListener(key.slice(2), value);
            else if (value !== false && value !== null) element.setAttribute(key, value === true ? '' : value);
        }
        for (const child of children) if (child !== null && child !== undefined) element.append(child);
        return element;
    };
    const request = async (path, method = 'GET', data) => {
        const multipart = data instanceof FormData;
        const response = await fetch(path, { method, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
            headers: { Accept: 'application/json', ...(method === 'GET' ? {} : {
                'X-CSRF-Token': document.querySelector('#profile-photo-csrf input').value,
                ...multipart ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' },
            }) }, body: method === 'GET' ? undefined : multipart ? data : new URLSearchParams({ _workspace_json: JSON.stringify(data) }) });
        if (!response.ok) { const error = new Error(labels.error); error.status = response.status; throw error; }
        return response.json();
    };
    window.createPersonPhotos({ node, request, labels }).mount(host, '/portal/api/person/photo/');
})();
