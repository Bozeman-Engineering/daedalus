import { createSlice } from '@reduxjs/toolkit';
import store from '../store';

const fileDragSlice = createSlice({
  name: 'fileDrag',
  initialState: {
    enabled: true,
  },
  reducers: {
    setFileDrag: (state, action) => {
      state.enabled = action.payload;
    },
  },
});

export const { setFileDrag } = fileDragSlice.actions;
export default fileDragSlice.reducer;

window.setFileDrag = (enabled) => {
  store.dispatch(setFileDrag({ enabled }));
};
