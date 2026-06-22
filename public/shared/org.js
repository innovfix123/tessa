(function () {
    'use strict';

    function renderReportCard(person) {
        return `
            <article class="org-report-card">
                <div class="org-report-top"></div>
                <div class="org-report-name">${person.name}</div>
                <div class="org-report-role">${person.role}</div>
            </article>
        `;
    }

    function renderTreeRows(rows) {
        return rows.map((row) => `
            <div class="org-tree-row ${row.levelClass || ''}">
                <span class="org-tree-branch">└</span>
                <span class="org-tree-name">${row.name}</span>
                <span class="org-tree-role">${row.role}</span>
                ${row.tag ? `<span class="org-tree-tag">${row.tag}</span>` : ''}
            </div>
        `).join('');
    }

    function renderDepartment(title, subtitle, rows) {
        return `
            <article class="org-dept-card">
                <h4>${title}</h4>
                <div class="org-dept-sub">${subtitle}</div>
                <div class="org-dept-sep"></div>
                <div class="org-tree-list">
                    ${renderTreeRows(rows)}
                </div>
            </article>
        `;
    }

    function initOrgView(containerId) {
        const container = document.getElementById(containerId);
        if (!container || container.dataset.mounted === '1') {
            return;
        }
        container.dataset.mounted = '1';

        const directReports = [
            { name: 'Bala', role: 'COO' },
            { name: 'Sneha Sunoj', role: 'Hima PM (acting)' },
            { name: 'Nandha', role: 'CMO' },
            { name: 'Ayush', role: 'CFO' },
            { name: 'Yuvanesh', role: 'Tech Lead — All Apps + Hima Strategist' },
            { name: 'Fida Taneem', role: 'Lead AI Engineer' },
            { name: 'Akshara', role: 'HR' }
        ];

        const opsRows = [
            { name: 'Deeksha', role: 'Team Lead-Support' },
            { name: 'Gousia', role: 'Telugu Support', levelClass: 'level-1', tag: '→ Deeksha' },
            { name: 'Reshma', role: 'Malayalam Support', levelClass: 'level-1', tag: '→ Deeksha' },
            { name: 'Anjali Bhatt', role: 'Bengali + Hindi Support', levelClass: 'level-1', tag: '→ Deeksha' },
            { name: 'Smrithy', role: 'Tamil Support', levelClass: 'level-1', tag: '→ Deeksha' },
            { name: 'Meghana', role: 'Business Analyst' },
            { name: 'Anindita', role: 'Growth Manager, North India' },
            { name: 'Gargi Bisht', role: 'Social Media Manager', levelClass: 'level-1', tag: '→ Anindita' }
        ];

        const cooRows = [
            { name: 'Suwetha S', role: 'Technical Support', tag: 'Only Care' },
            { name: 'Rachita', role: 'Technical Support', tag: 'Only Care' },
            { name: 'Dhanalakshmi', role: 'Technical Support', tag: 'Only Care' },
            { name: 'Nitha Sheri', role: 'Malayalam Technical Support', tag: 'Only Care' },
            { name: 'Dhanush', role: 'Product Manager', tag: 'Bangalore Connect' }
        ];

        const marketingRows = [
            { name: 'Anirudh', role: 'Performance Marketing Lead' },
            { name: 'Swapna M', role: 'Junior Performance Marketer', levelClass: 'level-1', tag: '→ Anirudh' },
            { name: 'Krishnan', role: 'Creative Head' },
            { name: 'Kishore Prabakaran', role: 'Content Lead — Hima', levelClass: 'level-1', tag: '→ Krishnan' },
            { name: 'Nehal Y', role: 'Content Creator', levelClass: 'level-2', tag: 'Hima' },
            { name: 'Fathima K P', role: 'Content Creator', levelClass: 'level-2', tag: 'Hima' },
            { name: 'Tiyasa', role: 'Content Creator', levelClass: 'level-2', tag: 'Unman' },
            { name: 'Haripriya', role: 'Content Creator', levelClass: 'level-2', tag: 'Hima' },
            { name: 'Disha', role: 'Content Creator', levelClass: 'level-2', tag: 'Hima' },
            { name: 'Sivaranjani N', role: 'Content Lead — Only Care', levelClass: 'level-1', tag: '→ Krishnan' },
            { name: 'Sooraj', role: 'Graphic Designer', levelClass: 'level-1', tag: '→ Krishnan' },
            { name: 'Anaz', role: 'Video Editor', levelClass: 'level-1', tag: '→ Krishnan' }
        ];

        const financeRows = [
            { name: 'Shoyab', role: 'Accountant' },
            { name: 'Karuna Behal', role: 'Finance Intern' },
            { name: 'Irisha', role: "Founder's Office", tag: 'leaving end-of-month' }
        ];

        const aiFidaRows = [
            { name: 'Bhuvan Prasad', role: 'AI Intern' },
            { name: 'Bhoomika', role: 'AI Intern' },
            { name: 'Soundarya Balaraddi', role: 'AI Intern' }
        ];

        const engineeringRows = [
            { name: 'Rishabh', role: 'Team Lead', tag: 'Hima' },
            { name: 'Perumal', role: 'Full Stack Dev', levelClass: 'level-1', tag: 'Hima' },
            { name: 'Barkha Agarwal', role: 'Intern', levelClass: 'level-1', tag: 'Astro' },
            { name: 'Sumit', role: 'Full Stack Developer Intern', levelClass: 'level-1', tag: '→ Rishabh · BLR Connect' },
            { name: 'Maari', role: 'Full Stack Dev', levelClass: 'level-1', tag: '→ Rishabh · Only Care' },
            { name: 'Ranjini', role: 'QA Lead', tag: 'All Apps' },
            { name: 'Laxmi', role: 'QA Intern', levelClass: 'level-1', tag: 'Hima' },
            { name: 'Iksha H S', role: 'QA Intern', levelClass: 'level-1', tag: 'Only Care' },
            { name: 'Priya', role: 'Content Moderator & QA', levelClass: 'level-1', tag: '→ Ranjini' },
            { name: 'Sneha Prathap', role: 'Gen AI Developer', tag: 'Unman' },
            { name: 'Tamil Arasan', role: 'Product Designer', tag: 'All Apps' },
            { name: 'Saran', role: 'Data Analyst', tag: 'Hima' },
            { name: 'Prajwal', role: 'Data Analyst Intern', levelClass: 'level-1', tag: '→ Saran' }
        ];

        const allRows = [
            ...directReports,
            ...opsRows,
            ...cooRows,
            ...marketingRows,
            ...financeRows,
            ...aiFidaRows,
            ...engineeringRows
        ];
        const uniqueNames = new Set(['JP', ...allRows.map((p) => p.name.trim())]);
        const peopleCount = uniqueNames.size;

        container.innerHTML = `
            <section class="portal-view org-view old-org">
                <div class="org-top">
                    <div class="org-brand">
                        <span class="org-brand-main">InnovFix</span>
                        <span class="org-brand-sub">ORG STRUCTURE • JUN 2026</span>
                    </div>
                    <div class="org-stats">
                        <div class="org-stat"><span>${peopleCount}</span><small>PEOPLE</small></div>
                        <div class="org-stat"><span>6</span><small>PRODUCTS</small></div>
                        <div class="org-stat"><span>5</span><small>DEPTS</small></div>
                    </div>
                </div>

                <div class="org-divider"></div>

                <div class="org-ceo-wrap">
                    <article class="org-ceo-box">
                        <div class="org-ceo-name">JP</div>
                        <div class="org-ceo-role">CEO</div>
                    </article>
                    <div class="org-ceo-line"></div>
                </div>

                <div class="org-section-title">DIRECT REPORTS</div>
                <section class="org-reports-grid">
                    ${directReports.map(renderReportCard).join('')}
                </section>

                <div class="org-section-title with-lines">DEPARTMENTS</div>
                <section class="org-dept-grid">
                    ${renderDepartment('Sneha Sunoj — Hima PM (acting)', 'Hima Product · Support · N. India Growth', opsRows)}
                    ${renderDepartment('Bala — COO', 'Product Operations', cooRows)}
                    ${renderDepartment('Nandha — CMO', 'Performance Marketing & Creative (paid + ad creative)', marketingRows)}
                    ${renderDepartment('Ayush — CFO', 'Finance', financeRows)}
                    ${renderDepartment('Fida Taneem — Lead AI Engineer', 'AI Platform & R&D (reports to CEO)', aiFidaRows)}
                    ${renderDepartment('Yuvanesh — Tech Lead + Hima Strategist', 'All App Development · Hima Sprint Host', engineeringRows)}
                </section>
            </section>
        `;
    }

    window.InnovfixOrgChart = {
        mount: initOrgView
    };
})();
