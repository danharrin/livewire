import { afterEach, describe, it, vi, expect } from 'vitest'
import { fireAction, sendNavigateRequest } from './index'

describe('Navigate responses', () => {
    afterEach(() => vi.unstubAllGlobals())

    it.each([
        ['text/html; charset=UTF-8', null, 200, '<p>Page</p>'],
        ['Text/HTML ; charset=utf-8', 'inline', 200, '<p>Page</p>'],
        ['image/gif', null, 200, null],
        ['application/pdf', null, 200, null],
        ['text/plain', null, 200, null],
        ['application/json', null, 200, null],
        ['application/xhtml+xml', null, 200, null],
        ['text/html-not-really', null, 200, null],
        [null, null, 200, null],
        ['text/html', 'Attachment; filename="page.html"', 200, null],
        ['text/plain', null, 404, '<p>Page</p>'],
        ['text/html', null, 500, '<p>Page</p>'],
    ])('handles %s / %s / %s without treating resources as pages', async (contentType, disposition, status, expectedHtml) => {
        let response = new Response('<p>Page</p>', { status })
        response.headers.delete('Content-Type')
        if (contentType) response.headers.set('Content-Type', contentType)
        if (disposition) response.headers.set('Content-Disposition', disposition)
        Object.defineProperty(response, 'url', { value: new URL('/final-resource', document.baseURI).href })
        vi.stubGlobal('fetch', async () => response)

        let result

        await sendNavigateRequest('/redirect#old-fragment', (html, destination, status) => {
            result = { html, destination: destination.href, status }
        }, error => { throw error })

        expect(result).toEqual({
            html: expectedHtml,
            destination: new URL('/final-resource', document.baseURI).href,
            status,
        })
    })

    it('preserves the fragment when a resource response has not redirected', async () => {
        let response = new Response('resource', { headers: { 'Content-Type': 'text/plain' } })
        Object.defineProperty(response, 'url', { value: new URL('/resource', document.baseURI).href })
        vi.stubGlobal('fetch', async () => response)

        let result

        await sendNavigateRequest('/resource#fragment', (html, destination) => {
            result = { html, destination: destination.href }
        }, error => { throw error })

        expect(result).toEqual({ html: null, destination: new URL('/resource#fragment', document.baseURI).href })
    })
})

describe('Request System', () => {
    // Test is incomplete, so we'll skip it for now...
    it.skip('should say hello world', async () => {
        global.fetch = vi.fn(() =>
            Promise.resolve({
                json: () => Promise.resolve(JSON.stringify({
                    components: [
                        {
                            snapshot: JSON.stringify({
                                memo: { id: '1' },
                            }),
                        },
                    ],
                    assets: {},
                })),
            }),
        )

        let component = createComponent()

        let results = await fireAction(component, '$refresh')

        expect(results).toBe('hello world')
    })
})

function createComponent() {
    return {
        get el() {
            throw new Error('el not implemented')
        },

        id: '1',

        effects: {},

        snapshot: {
            memo: {
                id: '1',
            },
        },

        islands: {},

        getUpdates() {
            //
        },

        getEncodedSnapshotWithLatestChildrenMergedIn() {
            //
        },

        processEffects() {
            //
        },

        mergeNewSnapshot() {
            //
        },
    }
}
