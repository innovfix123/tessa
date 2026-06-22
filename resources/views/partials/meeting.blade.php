<div class="mtg-container">
    <div class="mtg-top-row">
        <div class="mtg-week-nav">
            <button class="mtg-nav-btn" id="prevWeek">&#8592;</button>
            <div class="mtg-week-info">
                <span class="mtg-week-label" id="weekLabel"></span>
                <span class="mtg-week-range" id="weekRange"></span>
            </div>
            <button class="mtg-nav-btn" id="nextWeek">&#8594;</button>
        </div>
    </div>

    <div class="mtg-layout">
        <aside class="mtg-sidebar">
            <div class="mtg-sidebar-title">Scheduled Meetings</div>
            <div class="mtg-list" id="meetingList"></div>
            <div class="mtg-sidebar-footer" id="addMeetingWrap"></div>
        </aside>

        <section class="mtg-main">
            <div class="mtg-empty" id="emptyState">
                <div class="mtg-empty-icon">&#128203;</div>
                <h3>Select a Meeting</h3>
                <p>Choose a meeting from the sidebar to view agenda, action items, and minutes.</p>
            </div>

            <div class="mtg-detail hidden" id="meetingDetail">
                <div class="mtg-detail-header" id="detailHeader"></div>

                <nav class="mtg-tabs">
                    <button class="mtg-tab active" data-tab="agenda">
                        <span class="mtg-tab-icon">&#128196;</span> Agenda
                    </button>
                    @if($hasPreviousMinutes ?? false)
                    <button class="mtg-tab" data-tab="lastMom">
                        <span class="mtg-tab-icon">&#128197;</span> Previous Minutes
                    </button>
                    @endif
                    <button class="mtg-tab" data-tab="notes">
                        <span class="mtg-tab-icon">&#128221;</span> Minutes of Meeting
                    </button>
                    <button class="mtg-tab" data-tab="attendance">
                        <span class="mtg-tab-icon">&#128101;</span> Attendance
                    </button>
                </nav>

                <div class="mtg-tab-panel active" id="tab-agenda">
                    <div class="mtg-section-head">
                        <h4>Agenda</h4>
                        <span class="mtg-section-hint">Responses are auto-saved when you leave a field</span>
                    </div>
                    <div id="agendaSections"></div>
                    <div class="mtg-agenda-toolbar" id="agendaToolbar">
                        <form class="mtg-agenda-add" id="sectionAddForm">
                            <input type="text" id="meetingNewSectionTitle" class="mtg-input" placeholder="New section title..." required>
                            <button type="submit" class="mtg-btn mtg-btn-primary">+ Section</button>
                        </form>
                    </div>
                </div>

                @if($hasPreviousMinutes ?? false)
                <div class="mtg-tab-panel" id="tab-lastMom">
                    <div class="mtg-section-head">
                        <h4>Minutes from Previous Week</h4>
                    </div>
                    <div class="last-mom-content" id="lastMomContent">
                        <p class="no-data">No notes from previous meeting.</p>
                    </div>
                </div>
                @endif

                <div class="mtg-tab-panel" id="tab-attendance">
                    <div class="mtg-section-head">
                        <h4>Attendance</h4>
                        <span class="mtg-section-hint">Auto-tracked from Slack Huddle AI notes</span>
                    </div>
                    <div id="attendanceContent" style="padding:8px 0">
                        <p class="no-data">Select a meeting to view attendance.</p>
                    </div>
                </div>

                <div class="mtg-tab-panel" id="tab-notes">
                    <div class="mtg-section-head">
                        <h4>Minutes of Meeting</h4>
                        <span class="mtg-section-hint">Document key discussions, decisions, and outcomes</span>
                    </div>
                    <textarea id="meetingNotes" class="mtg-notes-area" rows="10" placeholder="Write meeting minutes here..." data-grammar-fix></textarea>
                    <div class="mtg-notes-footer">
                        <button class="mtg-btn mtg-btn-primary" id="saveNotesBtn">Save Minutes</button>
                        <span class="mtg-save-status" id="saveStatus"></span>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
