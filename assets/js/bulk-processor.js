/*
 * Compressly bulk processor.
 *
 * Drives the AJAX loop for Media → Compressly. Vanilla JS, fetch API,
 * no jQuery.
 *
 * Page-load policy: the loop NEVER fires implicitly. The initial state
 * fetch only renders what the server reports — if a previous run was
 * still flagged "running" or "paused" (e.g. the operator closed the
 * tab mid-batch), the page shows a resume banner with Resume and
 * Cancel-and-Reset buttons. The loop only runs in response to an
 * explicit user click on Start, Resume, or the Resume banner. This
 * exists to prevent a credit-burn regression in which reopening the
 * bulk page silently re-pumped the SDK against ShortPixel.
 */

( function () {
    'use strict';

    var root = document.querySelector( '.compressly-bulk' );
    if ( ! root ) {
        return;
    }

    var cfg = ( window.compresslyBulk || {} );
    if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
        return;
    }

    var strings = cfg.strings || {};
    var loopActive = false;

    function $( selector ) {
        return root.querySelector( selector );
    }

    function setText( selector, text ) {
        var el = $( selector );
        if ( el ) { el.textContent = String( text ); }
    }

    function setVisible( selector, visible ) {
        var el = $( selector );
        if ( ! el ) { return; }
        if ( visible ) { el.removeAttribute( 'hidden' ); }
        else { el.setAttribute( 'hidden', '' ); }
    }

    function ajax( action, params ) {
        var body = new URLSearchParams();
        body.append( 'action', 'compressly_' + action );
        body.append( '_wpnonce', cfg.nonce );
        if ( params ) {
            Object.keys( params ).forEach( function ( key ) {
                body.append( key, params[ key ] );
            } );
        }
        return fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        } ).then( function ( resp ) {
            return resp.json();
        } ).then( function ( payload ) {
            if ( ! payload || ! payload.success ) {
                var msg = payload && payload.data && payload.data.message
                    ? payload.data.message
                    : 'AJAX error';
                throw new Error( msg );
            }
            return payload.data;
        } );
    }

    function formatBytes( bytes ) {
        bytes = Math.max( 0, parseInt( bytes, 10 ) || 0 );
        var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
        var i = 0;
        while ( bytes >= 1024 && i < units.length - 1 ) {
            bytes = bytes / 1024;
            i++;
        }
        return bytes.toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
    }

    function formatStatus( status ) {
        if ( ! status ) { return strings.idle || 'Idle'; }
        if ( status === 'running' )  { return strings.running  || 'Running…'; }
        if ( status === 'paused' )   { return strings.paused   || 'Paused'; }
        if ( status === 'complete' ) { return strings.complete || 'Complete'; }
        return strings.idle || 'Idle';
    }

    function formatTimeAgo( startedAt ) {
        var now = Math.floor( Date.now() / 1000 );
        var diff = Math.max( 0, now - ( parseInt( startedAt, 10 ) || 0 ) );
        if ( diff < 60 ) {
            return ( strings.agoSeconds || '%d seconds' ).replace( '%d', diff );
        }
        var minutes = Math.floor( diff / 60 );
        if ( minutes < 60 ) {
            var minTpl = minutes === 1
                ? ( strings.agoMinute || '%d minute' )
                : ( strings.agoMinutes || '%d minutes' );
            return minTpl.replace( '%d', minutes );
        }
        var hours = Math.floor( minutes / 60 );
        var hourTpl = hours === 1
            ? ( strings.agoHour || '%d hour' )
            : ( strings.agoHours || '%d hours' );
        return hourTpl.replace( '%d', hours );
    }

    function updateRing( percent ) {
        var ring = $( '[data-compressly-ring]' );
        if ( ! ring ) { return; }
        var radius = parseFloat( ring.getAttribute( 'r' ) ) || 34;
        var circumference = 2 * Math.PI * radius;
        ring.style.strokeDasharray = circumference;
        ring.style.strokeDashoffset = circumference * ( 1 - Math.min( 1, Math.max( 0, percent / 100 ) ) );
    }

    function renderStats( stats ) {
        if ( ! stats ) { return; }
        setText( '[data-compressly-stat="total"]',       ( stats.total       || 0 ).toLocaleString() );
        setText( '[data-compressly-stat="optimized"]',   ( stats.optimized   || 0 ).toLocaleString() );
        setText( '[data-compressly-stat="pending"]',     ( stats.pending     || 0 ).toLocaleString() );
        setText( '[data-compressly-stat="failed"]',      ( stats.failed      || 0 ).toLocaleString() );
        setText( '[data-compressly-stat="bytes_saved"]', formatBytes( stats.bytes_saved ) );
    }

    function renderState( state ) {
        if ( ! state ) { return; }

        var status = state.status || 'idle';
        var isRunning = status === 'running';
        var isPaused  = status === 'paused';
        var isComplete = status === 'complete';
        var failedItems = Array.isArray( state.failed_items ) ? state.failed_items : [];
        var hasFailures = failedItems.length > 0;

        setText( '[data-compressly-status]', formatStatus( status ) );

        var totalAtStart = parseInt( state.total_at_start, 10 ) || 0;
        var rawDone = ( parseInt( state.processed, 10 ) || 0 )
                    + ( parseInt( state.failed, 10 ) || 0 )
                    + ( parseInt( state.skipped, 10 ) || 0 );
        // Clamp the displayed "done" so a drift past total_at_start
        // (e.g. concurrent uploads during a run) can no longer
        // render as "335 of 159".
        var done = totalAtStart > 0 ? Math.min( rawDone, totalAtStart ) : rawDone;
        var percent = totalAtStart > 0 ? Math.min( 100, Math.round( ( done / totalAtStart ) * 100 ) ) : ( isComplete ? 100 : 0 );
        setText( '[data-compressly-percent]', percent + '%' );
        updateRing( percent );

        var detailTemplate = strings.detail || '%1$s of %2$s';
        var detail = detailTemplate
            .replace( '%1$s', done.toLocaleString() )
            .replace( '%2$s', totalAtStart.toLocaleString() );
        setText( '[data-compressly-detail]', totalAtStart > 0 ? detail : '' );

        setVisible( '[data-compressly-action="start"]',  ! isRunning && ! isPaused );
        setVisible( '[data-compressly-action="pause"]',  isRunning );
        setVisible( '[data-compressly-action="resume"]', isPaused );
        setVisible( '[data-compressly-action="cancel"]', isRunning || isPaused );
        setVisible( '[data-compressly-action="retry-failed"]', hasFailures && ! isRunning );

        var list = $( '[data-compressly-error-list]' );
        if ( list ) {
            list.innerHTML = '';
            failedItems.slice().reverse().forEach( function ( item ) {
                if ( ! item || typeof item !== 'object' ) { return; }
                var li = document.createElement( 'li' );
                li.textContent = '#' + ( item.id || '?' ) + ': ' + ( item.error || '' );
                list.appendChild( li );
            } );
        }
        setText( '[data-compressly-error-count]', failedItems.length );
    }

    /**
     * Banner only shows on initial page load and only when the server
     * reports an unfinished run. It is the user's explicit choice
     * whether to Resume (kick off the loop) or Cancel (wipe state).
     */
    function renderResumeBanner( state ) {
        if ( ! state ) {
            setVisible( '[data-compressly-resume-banner]', false );
            return;
        }
        var status = state.status || 'idle';
        if ( status !== 'running' && status !== 'paused' ) {
            setVisible( '[data-compressly-resume-banner]', false );
            return;
        }

        var totalAtStart = parseInt( state.total_at_start, 10 ) || 0;
        var rawDone = ( parseInt( state.processed, 10 ) || 0 )
                    + ( parseInt( state.failed, 10 ) || 0 )
                    + ( parseInt( state.skipped, 10 ) || 0 );
        var done = totalAtStart > 0 ? Math.min( rawDone, totalAtStart ) : rawDone;
        var ago = formatTimeAgo( state.started_at );

        var template = status === 'paused'
            ? ( strings.resumePaused || 'Previous bulk is paused (started %1$s ago, %2$s of %3$s processed).' )
            : ( strings.resumeRunning || 'Previous bulk in progress (started %1$s ago, %2$s of %3$s processed).' );

        var text = template
            .replace( '%1$s', ago )
            .replace( '%2$s', done.toLocaleString() )
            .replace( '%3$s', totalAtStart.toLocaleString() );

        setText( '[data-compressly-resume-text]', text );
        setVisible( '[data-compressly-resume-banner]', true );
    }

    function hideResumeBanner() {
        setVisible( '[data-compressly-resume-banner]', false );
    }

    function applySnapshot( data ) {
        if ( ! data ) { return; }
        renderStats( data.stats );
        renderState( data.state );
    }

    function startLoop() {
        if ( loopActive ) { return; }
        loopActive = true;
        runBatch();
    }

    function stopLoop() {
        loopActive = false;
    }

    function runBatch() {
        if ( ! loopActive ) { return; }
        ajax( 'bulk_process_batch' ).then( function ( data ) {
            applySnapshot( data );
            var stillRunning = data && data.state && data.state.status === 'running';
            var anyAttempted = data && data.batch && data.batch.attempted > 0;
            if ( stillRunning && anyAttempted && loopActive ) {
                // Yield briefly so the browser can paint and the user
                // can interact with the pause/cancel buttons.
                window.setTimeout( runBatch, 100 );
            } else {
                stopLoop();
            }
        } ).catch( function ( err ) {
            stopLoop();
            window.console && console.error && console.error( err );
            window.alert( strings.batchError || 'Batch failed; pausing the run.' );
            ajax( 'bulk_pause' ).then( applySnapshot ).catch( function () {} );
        } );
    }

    function handleAction( action ) {
        switch ( action ) {
            case 'start':
                hideResumeBanner();
                ajax( 'bulk_start' ).then( function ( data ) {
                    applySnapshot( data );
                    if ( data && data.state && data.state.status === 'running' ) {
                        startLoop();
                    }
                } ).catch( function ( err ) {
                    window.alert( ( strings.startError || 'Could not start the bulk run.' ) + ' ' + err.message );
                } );
                break;

            case 'pause':
                stopLoop();
                ajax( 'bulk_pause' ).then( applySnapshot ).catch( function ( err ) {
                    window.console && console.error && console.error( err );
                } );
                break;

            case 'resume':
            case 'resume-from-banner':
                hideResumeBanner();
                ajax( 'bulk_resume' ).then( function ( data ) {
                    applySnapshot( data );
                    startLoop();
                } ).catch( function ( err ) {
                    window.console && console.error && console.error( err );
                } );
                break;

            case 'cancel':
            case 'cancel-from-banner':
                stopLoop();
                hideResumeBanner();
                ajax( 'bulk_cancel' ).then( applySnapshot ).catch( function ( err ) {
                    window.console && console.error && console.error( err );
                } );
                break;

            case 'retry-failed':
                ajax( 'bulk_retry_failed' ).then( function ( data ) {
                    applySnapshot( data );
                } ).catch( function ( err ) {
                    window.console && console.error && console.error( err );
                } );
                break;

            case 'restore':
                handleRestore();
                break;
        }
    }

    function handleRestore() {
        var input = $( '[data-compressly-restore-id]' );
        var output = $( '[data-compressly-restore-output]' );
        var id = input ? parseInt( input.value, 10 ) : 0;
        if ( ! id || id <= 0 ) {
            if ( output ) { output.textContent = strings.restorePrompt || 'Enter an attachment ID first.'; }
            return;
        }
        if ( output ) { output.textContent = '…'; }
        ajax( 'bulk_restore', { attachment_id: id } ).then( function ( data ) {
            if ( ! data || ! data.restore ) { return; }
            applySnapshot( { state: data.state, stats: data.stats } );
            var template = strings.restoreSuccess || 'Restored %1$d files (skipped %2$d, errors %3$d).';
            var message = template
                .replace( '%1$d', data.restore.restored || 0 )
                .replace( '%2$d', data.restore.skipped || 0 )
                .replace( '%3$d', ( data.restore.errors || [] ).length );
            if ( output ) { output.textContent = message; }
        } ).catch( function ( err ) {
            var template = strings.restoreError || 'Restore failed: %s';
            if ( output ) { output.textContent = template.replace( '%s', err.message ); }
        } );
    }

    root.addEventListener( 'click', function ( event ) {
        var btn = event.target.closest( '[data-compressly-action]' );
        if ( ! btn ) { return; }
        event.preventDefault();
        handleAction( btn.getAttribute( 'data-compressly-action' ) );
    } );

    // Initial load: fetch state once, render it, and surface the
    // resume banner if a previous run is still flagged running or
    // paused. CRUCIALLY this never calls startLoop() — the loop only
    // fires from an explicit user click. See file header comment.
    ajax( 'bulk_stats' ).then( function ( data ) {
        applySnapshot( data );
        renderResumeBanner( data && data.state );
    } ).catch( function ( err ) {
        window.console && console.error && console.error( err );
    } );
} )();
