import {api} from "./client";
import {publicApi} from "./public-client.ts";
import {
    CreateSaleLinkRequest,
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
    Order,
    QueryFilters,
    SaleLink,
    SaleLinkPublic,
} from "../types";
import {queryParamsHelper} from "../utilites/queryParamsHelper.ts";

export const saleLinkClient = {
    all: async (eventId: IdParam, pagination: QueryFilters) => {
        const response = await api.get<GenericPaginatedResponse<SaleLink>>(
            `events/${eventId}/sale-links` + queryParamsHelper.buildQueryString(pagination),
        );
        return response.data;
    },
    create: async (eventId: IdParam, saleLink: CreateSaleLinkRequest) => {
        const response = await api.post<GenericDataResponse<SaleLink>>(`events/${eventId}/sale-links`, saleLink);
        return response.data;
    },
    revoke: async (eventId: IdParam, saleLinkId: IdParam) => {
        const response = await api.post<GenericDataResponse<SaleLink>>(`events/${eventId}/sale-links/${saleLinkId}/revoke`);
        return response.data;
    },
}

export const saleLinkClientPublic = {
    findByToken: async (eventId: IdParam, token: string) => {
        const response = await publicApi.get<GenericDataResponse<SaleLinkPublic>>(
            `events/${eventId}/sale-links/${encodeURIComponent(token)}`
        );
        return response.data;
    },
    createOrder: async (eventId: IdParam, token: string) => {
        const response = await publicApi.post<GenericDataResponse<Order>>(
            `events/${eventId}/sale-links/${encodeURIComponent(token)}/order`
        );
        return response.data;
    },
}
