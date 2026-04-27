/*
 * Compressly bulk processor.
 *
 * Drives the AJAX loop for Media → Compressly. Vanilla JS, fetch API,
 * no jQuery. Polling stops when the server reports a non-running
 * status or returns an empty batch (queue exhausted).
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

    function updateRing( percent ) {
        var ring = $( '[data-compressly-ring]' );
        if ( ! ring ) { return; }
        var radius = parseFloat( ring.getAttribute( 'r' ) ) || 34;
        var circumference = 2 * Math.PI * radius;
        ring.style.strokeDasharray = circumference;
        ring.style.strokeDashoffset = circumference * ( 1 - Math.min( 1, Math.max( 0, percent / 100 ) ) );
    }

    function renderStats( stats, state ) {
        if ( ! stats ) { return; }
        setText( '[data-compressly-stat="total"]',     ( stats.total       || 0 ).toLocaleString() );
        setText( '[data-compressly-stat="optimized"]', ( stats.optimized   || 0 ).toLocaleString() );
        setText( '[data-compressly-stat="pending"]',   ( stats.pending     || 0 ).toLocaleString() );
        setText( '[data-compressly-stat="bytes_saved"]', formatBytes( stats.bytes_saved ) );

        var failedCount = state && Array.isArray( state.failed_items ) ? state.failed_items.length : 0;
        setText( '[data-compressly-stat="failed"]', failedCount.toLocaleString() );
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
        var done = ( parseInt( state.processed, 10 ) || 0 )
                 + ( parseInt( state.failed, 10 ) || 0 )
                 + ( parseInt( state.skipped, 10 ) || 0 );
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

    function applySnapshot( data ) {
        if ( ! data ) { return; }
        renderStats( data.stats, data.state );
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
                ajax( 'bulk_resume' ).then( function ( data ) {
                    applySnapshot( data );
                    startLoop();
                } ).catch( function ( err ) {
                    window.console && console.error && console.error( err );
                } );
                break;

            case 'cancel':
                stopLoop();
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

    // Initial load: pull current state and auto-resume if a run was
    // already running when the page was opened (e.g. user navigated
    // away mid-bulk and came back).
    ajax( 'bulk_stats' ).then( function ( data ) {
        applySnapshot( data );
        if ( data && data.state && data.state.status === 'running' ) {
            startLoop();
        }
    } ).catch( function ( err ) {
        window.console && console.error && console.error( err );
    } );
} )();
